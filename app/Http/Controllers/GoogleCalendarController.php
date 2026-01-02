<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Client;
use Google\Service\Calendar;

class GoogleCalendarController extends Controller
{
    private function getClient()
    {
        $client = new Client();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));
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
        
        // Save the token to a secure file on your Render server
        file_put_contents(storage_path('google_token.json'), json_encode($token));
        
        return "Connected! You can now close this tab and check your TRMNL.";
    }

    public function getTrmnlData()
    {
        $client = $this->getClient();
        $tokenPath = storage_path('google_token.json');

        if (!file_exists($tokenPath)) {
            return response()->json(['error' => 'Not authenticated. Visit /google/auth first.'], 401);
        }

        $client->setAccessToken(json_decode(file_get_contents($tokenPath), true));

        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }

        $service = new Calendar($client);
        $events = $service->events->listEvents('primary', [
            'maxResults' => 15,
            'orderBy' => 'startTime',
            'singleEvents' => true,
            'timeMin' => date('c'),
        ]);

        $data = ['events' => []];
        foreach ($events->getItems() as $event) {
            $data['events'][] = [
                'title' => $event->getSummary(),
                'time' => date('H:i', strtotime($event->start->dateTime ?? $event->start->date))
            ];
        }

        return response()->json($data);
    }
}
