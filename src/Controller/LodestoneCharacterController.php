<?php

namespace App\Controller;

use App\Common\Service\Redis\Redis;
use App\Service\Content\LodestoneCharacter;
use App\Service\LodestoneQueue\CharacterConverter;
use Lodestone\Api;
use Lodestone\Exceptions\LodestonePrivateException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\Routing\Annotation\Route;

class LodestoneCharacterController extends AbstractController
{
    /**
     * @Route("/Character/Search")
     * @Route("/character/search")
     */
    public function search(Request $request)
    {
        if (empty(trim($request->get('name')))) {
            throw new NotAcceptableHttpException('You must provide a name to search.');
        }

        $listView = (new Api())->character()->search(
            $request->get('name'),
            ucwords($request->get('server')),
            $request->get('page') ?: 1
        );
        if (isset($listView, $listView->Results)) {
            foreach ($listView->Results as $item) {
                if (isset($item->Lang)) {
                    Redis::cache()->set($item->ID . '_lang', $item->Lang, 10 * 60);
                }
            }
        }

        return $this->json($listView);
    }

    /**
     * @Route("/Characters")
     * @Route("/characters")
     * @throws \Exception
     */
    public function multi(Request $request)
    {
        $ids = explode(',', $request->get('ids'));
        if (count($ids) > 512) {
            throw new \Exception("Woah their calm down, 512+ characters wtf?");
        }

        $response = [];
        foreach ($ids as $id) {
            $response[] = $this->index($request, $id, true);
        }

        return $this->json($response);
    }

    /**
     * @Route("/Character/{lodestoneId}")
     * @Route("/character/{lodestoneId}")
     * @throws \Exception
     */
    public function index(Request $request, $lodestoneId, $internal = false)
    {
        $lodestoneId = (int)strtolower(trim($lodestoneId));

        // initialise api
        $api = new Api();

        // choose which content you want
        $data = $request->get('data') ? explode(',', strtoupper($request->get('data'))) : [];
        $isExtended = $request->get('extended');
        $content = (object)[
            'AC'  => in_array('AC', $data),
            'FR'  => in_array('FR', $data),
            'FC'  => in_array('FC', $data),
            'FCM' => in_array('FCM', $data),
            'PVP' => in_array('PVP', $data),
            'MIMO' => in_array('MIMO', $data),
        ];

        // response model
        $response = (Object)[
            'Character'          => $api->character()->get($lodestoneId),
            'Achievements'       => null,
            'AchievementsPublic' => null,
            'Friends'            => null,
            'FriendsPublic'      => null,
            'FreeCompany'        => null,
            'FreeCompanyMembers' => null,
            'PvPTeam'            => null,
        ];

        if ($charLang = Redis::cache()->get($lodestoneId.'_lang')) {
            $response->Character->Lang = $charLang;
        }
        // fc id + pvp team id
        $fcId  = $response->Character->FreeCompanyId;
        $pvpId = $response->Character->PvPTeamId;
        // ensure bio is UT8
        $response->Character->Bio = mb_convert_encoding($response->Character->Bio, 'UTF-8', 'UTF-8');

        CharacterConverter::handle($response->Character);

        if ($isExtended) {
            LodestoneCharacter::extendCharacterData($response->Character);
        }

        // Achievements
        if ($content->AC) {
            $achievements = [];
            $achievementsPublic = true;

            try {
                // achievements might be private/public, can check on 1st one
                $first = $api->character()->achievements($lodestoneId, 1);
            } catch (LodestonePrivateException $ex) {
                // we catch this exception as users will probably still want to handle the response (profile, other data)
                // even if achievements are private
                $achievementsPublic = false;
            }

            // add public status to response
            $response->AchievementsPublic = $achievementsPublic;

            if ($achievementsPublic && $first) {
                $achievements = array_merge($achievements, $first->Achievements);

                // parse the rest of the pages
                $api->config()->useAsync();
                foreach([2,3,4,5,6,8,11,12,13] as $kindId) {
                    $api->config()->setRequestId("kind_{$kindId}");
                    $api->character()->achievements($lodestoneId, $kindId);
                }

                foreach ($api->http()->settle() as $res) {
                    if (isset($res->Error)) {
                        continue;
                    }

                    $achievements = array_merge(
                        $achievements,
                        ($res && is_object($res)) ? $res->Achievements : []
                    );
                }

                $api->config()->useSync();
            }

            $response->Achievements = (Object)[
                'List' => [],
                'Points' => 0
            ];

            // simplify achievements
            foreach ($achievements as $i => $achi) {
                $response->Achievements->Points += $achi->Points;
                $response->Achievements->List[$i] = (Object)[
                    'ID'   => $achi->ID,
                    'Date' => $achi->ObtainedTimestamp
                ];
            }

            if ($isExtended) {
                LodestoneCharacter::extendAchievementData($response->Achievements);
            }
        }

        // Friends
        if ($content->FR) {
            $friends = [];
            $friendsPublic = true;

            // grab 1st page, so we know if there is more than 1 page
            try {
                $first   = $api->character()->friends($lodestoneId, 1);
                $friends = $first ? array_merge($friends, $first->Results) : $friends;
            } catch (LodestonePrivateException $ex) {
                // we catch this exception as users will probably still want to handle the response (profile, other data)
                // even if achievements are private
                $friendsPublic = false;
            }

            // add public status to response
            $response->FriendsPublic = $achievementsPublic;

            if ($friendsPublic && $first && $first->Pagination->PageTotal > 1) {
                // parse the rest of pages
                $api->config()->useAsync();
                foreach (range(2, $first->Pagination->PageTotal) as $page) {
                    $api->character()->friends($lodestoneId, $page);
                }

                foreach ($api->http()->settle() as $res) {
                    $friends = array_merge($friends, $res->Results);
                }
                $api->config()->useSync();
            }

            $response->Friends = $friends;
        }

        // Free Company
        if ($content->FC && $fcId) {
            $response->FreeCompany = $api->freecompany()->get($fcId);
        }

        // Free Company
        if ($content->MIMO) {
            $response->Minions = $api->character()->minions($lodestoneId);
            $response->Mounts = $api->character()->mounts($lodestoneId);
        }

        // Free Company Members
        if ($content->FCM && $fcId) {
            $members = [];

            // grab 1st page, so we know if there is more than 1 page
            $first   = $api->freecompany()->members($response->Character->FreeCompanyId, 1);
            $members = $first ? array_merge($members, $first->Results) : $members;

            if ($first && $first->Pagination->PageTotal > 1) {
                // parse the rest of pages
                $api->config()->useAsync();
                foreach (range(2, $first->Pagination->PageTotal) as $page) {
                    $api->freecompany()->members($response->Character->FreeCompanyId, $page);
                }

                foreach ($api->http()->settle() as $res) {
                    $members = array_merge($members, $res->Results);
                }
                $api->config()->useSync();
            }

            $response->FreeCompanyMembers = $members;
        }

        // PVP Team
        if ($content->PVP && $pvpId) {

            $response->PvPTeam = $api->pvpteam()->get($pvpId);
        }

        // ensure IDs exist
        $response->Character->ID = $lodestoneId;

        if ($response->FreeCompany && $response->Character->FreeCompanyId) {
            $response->FreeCompany->ID = $response->Character->FreeCompanyId;
        }


        if ($response->PvPTeam && $response->Character->PvPTeamId) {
            $response->PvPTeam->ID = (string)$response->Character->PvPTeamId;
        }

        if ($internal) {
            return $response;
        }

        return $this->json($response);
    }
}
