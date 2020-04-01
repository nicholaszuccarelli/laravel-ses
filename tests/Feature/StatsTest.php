<?php

namespace Juhasev\LaravelSes\Tests\Feature;

use Juhasev\LaravelSes\Facades\SesMail;
use Juhasev\LaravelSes\Mocking\TestMailable;
use Juhasev\LaravelSes\ModelResolver;
use Juhasev\LaravelSes\Models\Batch;
use Juhasev\LaravelSes\Repositories\EmailRepository;
use Juhasev\LaravelSes\Services\Stats;
use Juhasev\LaravelSes\Tests\FeatureTestCase;

class StatsTest extends FeatureTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->setupBasicCampaign();
    }

    public function testStatsForAnEmailEndPoint()
    {
        // add some more campaigns
        SesMail::enableAllTracking()
            ->setBatch('win_back')
            ->to("something@gmail.com")
            ->send(new TestMailable());

        SesMail::enableAllTracking()
            ->setBatch('june_newsletter')
            ->to("something@gmail.com")
            ->send(new TestMailable());


        $batch = Batch::resolve('win_back');

        $messageId = ModelResolver::get('SentEmail')::whereEmail('something@gmail.com')->where('batch_id', $batch->id)->first()->message_id;
        $fakeJson = json_decode($this->generateBounceJson($messageId, 'something@gmail.com'));
        
        $this->json('POST', 'laravel-ses/notification/bounce', (array)$fakeJson);

        $messageId = ModelResolver::get('SentEmail')::whereEmail('something@gmail.com')->where('batch_id', $batch->id)->first()->message_id;
        $fakeJson = json_decode($this->generateDeliveryJson($messageId, 'something@gmail.com'));
        $this->json('POST', '/laravel-ses/notification/delivery', (array)$fakeJson);

        $messageId = ModelResolver::get('SentEmail')::whereEmail('something@gmail.com')->where('batch_id', $batch->id)->first()->message_id;
        $fakeJson = json_decode($this->generateComplaintJson($messageId, 'something@gmail.com'));
        $this->json('POST', 'laravel-ses/notification/complaint', (array)$fakeJson);

        $links = ModelResolver::get('SentEmail')::whereEmail('something@gmail.com')
            ->where('batch_id', $batch->id)
            ->first()
            ->emailLinks;

        $linkId = $links->first()->link_identifier;
        $this->get("https://laravel-ses.com/laravel-ses/link/$linkId");

        $stats = Stats::statsForEmail('something@gmail.com');

        $expectedCounts = [
            "sent" => 3,
            "deliveries" => 2,
            "opens" => 1,
            "bounces" => 1,
            "complaints" => 1,
            "rejects" => 0,
            "clicks" => 2
        ];

        $this->assertEquals($expectedCounts, $stats);


        // Test email repository data agrees with stats
        $sent = EmailRepository::getSent('something@gmail.com');
        $this->assertCount(3, $sent);

        $deliveries = EmailRepository::getDeliveries('something@gmail.com');
        $this->assertCount(2, $deliveries);

        $opens = EmailRepository::getOpens('something@gmail.com');
        $this->assertCount(1, $opens);

        $bounces = EmailRepository::getBounces('something@gmail.com');
        $this->assertCount(1, $bounces);

        $complaints = EmailRepository::getComplaints('something@gmail.com');
        $this->assertCount(1, $complaints);

        $rejects = EmailRepository::getRejects('something@gmail.com');
        $this->assertCount(0, $rejects);

        $clicks = EmailRepository::getClicks('something@gmail.com');

        $this->assertCount(2, $clicks);

        // Test data for email
        $stats = Stats::dataForEmail('something@gmail.com');

        // Check sent emails
        $this->assertEquals("something@gmail.com", $stats['sent'][0]['email']);
        $this->assertEquals(Batch::resolve("welcome_emails")->id, $stats['sent'][0]['batch_id']);

        $this->assertEquals("something@gmail.com", $stats['sent'][1]['email']);
        $this->assertEquals(Batch::resolve("win_back")->id, $stats['sent'][1]['batch_id']);

        $this->assertEquals("something@gmail.com", $stats['sent'][2]['email']);
        $this->assertEquals(Batch::resolve("june_newsletter")->id, $stats['sent'][2]['batch_id']);

        // Check deliveries
        $this->assertEquals("something@gmail.com", $stats['deliveries'][0]['email']);
        $this->assertEquals(Batch::resolve("welcome_emails")->id, $stats['deliveries'][0]['batch_id']);

        $this->assertEquals("something@gmail.com", $stats['deliveries'][1]['email']);
        $this->assertEquals(Batch::resolve("win_back")->id, $stats['deliveries'][1]['batch_id']);

        // Check click through
        $this->assertEquals(1, $stats['clicks'][0]->emailLinks[0]->sent_email_id);
        $this->assertEquals('https://google.com', $stats['clicks'][0]->emailLinks[0]->original_url);
        $this->assertEquals(Batch::resolve('welcome_emails')->id, $stats['clicks'][0]->batch_id);

        $this->assertEquals(1, $stats['clicks'][0]->emailLinks[1]->sent_email_id);
        $this->assertEquals('https://superficial.io', $stats['clicks'][0]->emailLinks[1]->original_url);
        $this->assertEquals(Batch::resolve('welcome_emails')->id, $stats['clicks'][0]['batch_id']);

        $this->assertEquals(9, $stats['clicks'][1]->emailLinks[0]->sent_email_id);
        $this->assertEquals('https://google.com', $stats['clicks'][1]->emailLinks[0]->original_url);
        $this->assertEquals(Batch::resolve('win_back')->id, $stats['clicks'][1]['batch_id']);
    }

    public function testStatsForBatchEndPoint()
    {
        $stats = Stats::statsForBatch(Batch::resolve('welcome_emails'));

        $this->assertEquals([
            "sent" => 8,
            "deliveries" => 7,
            "opens" => 4,
            "bounces" => 1,
            "complaints" => 2,
            "rejects" => 0,
            "clicks" => 3,
            "link_popularity" => [
                "https://google.com" => [
                    "clicks" => 3
                ],
                "https://superficial.io" => [
                    "clicks" => 1
                ]
            ]
        ], $stats);
    }

    public function testStatsForNonExistingBatch()
    {
        $batch = Batch::resolve('lukaku');

        $this->assertNull($batch);
    }

    private function setupBasicCampaign()
    {
        SesMail::fake();

        $emails = [
            'something@gmail.com',
            'somethingelse@gmail.com',
            'ay@yahoo.com',
            'yo@hotmail.com',
            'hey@google.com',
            'no@gmail.com',
            'bounce@ses.com',
            'complaint@yes.com'
        ];

        foreach ($emails as $email) {
            SesMail::enableAllTracking()
                ->setBatch('welcome_emails')
                ->to($email)
                ->send(new TestMailable());
        }

        $statsForBatch = Stats::statsForBatch(Batch::resolve('welcome_emails'));

        // Make sure all stats are 0 apart except sent_emails
        $this->assertEquals(8, $statsForBatch['sent']);
        $this->assertEquals(0, $statsForBatch['deliveries']);
        $this->assertEquals(0, $statsForBatch['opens']);
        $this->assertEquals(0, $statsForBatch['complaints']);
        $this->assertEquals(0, $statsForBatch['rejects']);
        $this->assertEquals(0, $statsForBatch['clicks']);
        $this->assertEquals([], $statsForBatch['link_popularity']);

        //deliver all emails apart from bounced email
        foreach ($emails as $email) {
            if ($email != 'bounce@ses.com') {
                $messageId = ModelResolver::get('SentEmail')::whereEmail($email)->first()->message_id;
                $fakeJson = json_decode($this->generateDeliveryJson($messageId));
                $this->json(
                    'POST',
                    '/laravel-ses/notification/delivery',
                    (array)$fakeJson
                );
            }
        }

        //bounce an email
        $messageId = ModelResolver::get('SentEmail')::whereEmail('bounce@ses.com')->first()->message_id;
        $fakeJson = json_decode($this->generateBounceJson($messageId));
        $this->json('POST', 'laravel-ses/notification/bounce', (array)$fakeJson);

        //two complaints
        $messageId = ModelResolver::get('SentEmail')::whereEmail('complaint@yes.com')->first()->message_id;
        $fakeJson = json_decode($this->generateComplaintJson($messageId));
        $this->json('POST', 'laravel-ses/notification/complaint', (array)$fakeJson);

        $messageId = ModelResolver::get('SentEmail')::whereEmail('ay@yahoo.com')->first()->message_id;
        $fakeJson = json_decode($this->generateComplaintJson($messageId));
        $this->json('POST', 'laravel-ses/notification/complaint', (array)$fakeJson);

        //register 4 opens
        $openedEmails = [
            'something@gmail.com',
            'somethingelse@gmail.com',
            'hey@google.com',
            'no@gmail.com'
        ];

        foreach ($emails as $email) {
            if (in_array($email, $openedEmails)) {
                $sentEmailId = ModelResolver::get('SentEmail')::whereEmail($email)->first()->id;
                $id = ModelResolver::get('EmailOpen')::whereSentEmailId($sentEmailId)->first()->beacon_identifier;
                $this->get("laravel-ses/beacon/{$id}");
            }
        }

        //one user clicks both links
        $links = ModelResolver::get('SentEmail')::whereEmail('something@gmail.com')->first()->emailLinks;

        $linkId = $links->where('original_url', 'https://google.com')->first()->link_identifier;
        $this->get("https://laravel-ses.com/laravel-ses/link/$linkId");

        $linkId = $links->where('original_url', 'https://superficial.io')->first()->link_identifier;
        $this->get("https://laravel-ses.com/laravel-ses/link/$linkId");


        //one user clicks one link three times
        $links = ModelResolver::get('SentEmail')::whereEmail('hey@google.com')->first()->emailLinks;

        $linkId = $links->where('original_url', 'https://google.com')->first()->link_identifier;
        $this->get("https://laravel-ses.com/laravel-ses/link/$linkId");
        $this->get("https://laravel-ses.com/laravel-ses/link/$linkId");
        $this->get("https://laravel-ses.com/laravel-ses/link/$linkId");

        //one user clicks one link only
        $links = ModelResolver::get('SentEmail')::whereEmail('no@gmail.com')->first()->emailLinks;
        $linkId = $links->where('original_url', 'https://google.com')->first()->link_identifier;
        $this->get("https://laravel-ses.com/laravel-ses/link/$linkId");
    }
}
