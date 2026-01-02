<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Client;
use Google\Service\Calendar;
use Carbon\Carbon;

class GoogleCalendarController extends Controller
{
    private function getClient()
    {
        $client = new Client();
        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
        $client->addScope(Calendar::CALENDAR_READONLY);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        return $client;
    }

    public function redirectToGoogle()
    {
        return redirect()->away($this->getClient()->createAuthUrl());
    }

    public function handleGoogleCallback(Request $request)
    {
        $client = $this->getClient();
        $token = $client->fetchAccessTokenWithAuthCode($request->get('code'));
        file_put_contents(storage_path('google_token.json'), json_encode($token));
        return "Connected! You can now close this tab and check your TRMNL.";
    }

    public function getTrmnlData()
    {
        $client = $this->getClient();
        $tokenPath = storage_path('google_token.json');

        if (!file_exists($tokenPath)) {
            return response()->json(['error' => 'Not authenticated.'], 401);
        }

        $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));

        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                file_put_contents($tokenPath, json_encode($client->getAccessToken()));
            }
        }

        $service = new Calendar($client);

        // --- Weekly View Logic ---
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd   = Carbon::now()->endOfWeek(Carbon::SUNDAY);

        $days = [
            'Monday' => [], 'Tuesday' => [], 'Wednesday' => [],
            'Thursday' => [], 'Friday' => [], 'Saturday' => [], 'Sunday' => [],
        ];

        $events = $service->events->listEvents('primary', [
            'timeMin'      => $weekStart->toRfc3339String(),
            'timeMax'      => $weekEnd->toRfc3339String(),
            'singleEvents' => true,
            'orderBy'      => 'startTime',
        ]);

        foreach ($events->getItems() as $event) {
            $title = $event->getSummary() ?? 'Untitled';
            if ($event->getStart()->getDateTime()) {
                $start = Carbon::parse($event->getStart()->getDateTime());
                $time = $start->format('g:ia');
            } else {
                $start = Carbon::parse($event->getStart()->getDate());
                $time = 'All Day';
            }

            $dayName = $start->format('l');
            if (array_key_exists($dayName, $days)) {
                $days[$dayName][] = [
                    'title' => $title,
                    'time'  => $time,
                ];
            }
        }

        return response()->json(['days' => $days]);
    }
}
