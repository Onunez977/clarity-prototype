<?php

namespace Tests\Feature\Jobs;

use App\Actions\Activity\Log\DefaultLog;
use App\Actions\Activity\Log\HitsSearch;
use App\Actions\Activity\Log\HotlistsSearch;
use App\Actions\Activity\Log\ScansSearch;
use App\Jobs\LogFilterActivity;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LogFilterActivityTest extends TestCase
{
    #[Group('jobs')]
    public function test_it_doesnt_log_with_no_filter_state(): void
    {
        $job = new LogFilterActivity(
            log: 'Scans',
            filterState: [],
            types: ['ocr' => 'plateNumber'],
            user: User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 0);
    }

    #[Group('jobs')]
    public function test_it_doesnt_log_when_query_state_values_are_null(): void
    {
        $job = new LogFilterActivity(
            log: 'Scans',
            filterState: [
                'queryBuilder' => [
                    'rules' => [
                        [
                            'type' => 'ocr',
                            'data' => [
                                'settings' => [
                                    'text' => null,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            types: ['ocr' => 'plateNumber'],
            user: User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 0);
    }

    #[Group('jobs')]
    public function test_it_doesnt_log_when_filter_state_values_are_null(): void
    {
        $job = new LogFilterActivity(
            log: 'Scans',
            filterState: [
                'filters' => [
                    'ocr' => null,
                ],
            ],
            types: ['ocr' => 'plateNumber'],
            user: User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 0);
    }

    #[Group('jobs')]
    public function test_it_logs_with_nested_filter_state(): void
    {
        $job = new LogFilterActivity(
            log: 'Hits',
            filterState: [
                'scan' => [
                    'ocr' => '123456',
                ],
            ],
            types: ['scan.ocr' => 'plateNumber'],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => HitsSearch::$log_name,
            'description' => $user->email.' searched for hits by Plate',
        ]);

        $activity = Activity::query()
            ->where('log_name', HitsSearch::$log_name)
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['plateNumber']);
    }

    #[Group('jobs')]
    public function test_it_logs_scan_query_search(): void
    {
        $job = new LogFilterActivity(
            log: 'Scans',
            filterState: [
                'queryBuilder' => [
                    'rules' => [
                        [
                            'type' => 'ocr',
                            'data' => [
                                'settings' => [
                                    'text' => '123456',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            types: ['ocr' => 'plateNumber'],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => ScansSearch::$log_name,
            'description' => $user->email.' searched for scans by Plate',
        ]);

        $activity = Activity::query()
            ->where('log_name', ScansSearch::$log_name)
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['plateNumber']);
    }

    #[Group('jobs')]
    public function test_it_logs_scan_filter_search(): void
    {
        $job = new LogFilterActivity(
            log: 'Scans',
            filterState: [
                'filters' => [
                    'ocr' => '123456',
                ],
            ],
            types: ['ocr' => 'plateNumber'],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => ScansSearch::$log_name,
            'description' => $user->email.' searched for scans by Plate',
        ]);

        $activity = Activity::query()
            ->where('log_name', ScansSearch::$log_name)
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['plateNumber']);
    }

    #[Group('jobs')]
    public function test_it_logs_hotlist_query_search(): void
    {
        $job = new LogFilterActivity(
            log: 'Hotlists',
            filterState: [
                'queryBuilder' => [
                    'rules' => [
                        [
                            'type' => 'vin',
                            'data' => [
                                'settings' => [
                                    'text' => '123456',
                                ],
                            ],
                        ],
                        [
                            'type' => 'plate',
                            'data' => [
                                'settings' => [
                                    'text' => '654321',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            types: [
                'vin' => 'vin',
                'plate' => 'plateNumber',
            ],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => HotlistsSearch::$log_name,
            'description' => $user->email.' searched for hotlists by Plate, VIN',
        ]);

        $activity = Activity::query()
            ->where('log_name', HotlistsSearch::$log_name)
            ->first();

        $this->assertArrayHasKey('vin', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['vin']);

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals(['654321'], $activity->properties['plateNumber']);
    }

    #[Group('jobs')]
    public function test_it_logs_hotlist_filter_search(): void
    {
        $job = new LogFilterActivity(
            log: 'Hotlists',
            filterState: [
                'filters' => [
                    'vin' => '123456',
                    'plate' => '654321',
                ],
            ],
            types: [
                'vin' => 'vin',
                'plate' => 'plateNumber',
            ],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => HotlistsSearch::$log_name,
            'description' => $user->email.' searched for hotlists by Plate, VIN',
        ]);

        $activity = Activity::query()
            ->where('log_name', HotlistsSearch::$log_name)
            ->first();

        $this->assertArrayHasKey('vin', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['vin']);

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals(['654321'], $activity->properties['plateNumber']);
    }

    #[Group('jobs')]
    public function test_it_logs_hits_query_search(): void
    {
        $job = new LogFilterActivity(
            log: 'Hits',
            filterState: [
                'queryBuilder' => [
                    'rules' => [
                        [
                            'type' => 'vin',
                            'data' => [
                                'settings' => [
                                    'text' => '123456',
                                ],
                            ],
                        ],
                        [
                            'type' => 'plate',
                            'data' => [
                                'settings' => [
                                    'text' => '654321',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            types: [
                'vin' => 'vin',
                'plate' => 'plateNumber',
            ],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => HitsSearch::$log_name,
            'description' => $user->email.' searched for hits by Plate, VIN',
        ]);

        $activity = Activity::query()
            ->where('log_name', HitsSearch::$log_name)
            ->first();

        $this->assertArrayHasKey('vin', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['vin']);

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals(['654321'], $activity->properties['plateNumber']);
    }

    #[Group('jobs')]
    public function test_it_logs_hits_filter_search(): void
    {
        $job = new LogFilterActivity(
            log: 'Hits',
            filterState: [
                'filters' => [
                    'vin' => '123456',
                    'plate' => '654321',
                ],
            ],
            types: [
                'vin' => 'vin',
                'plate' => 'plateNumber',
            ],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => HitsSearch::$log_name,
            'description' => $user->email.' searched for hits by Plate, VIN',
        ]);

        $activity = Activity::query()
            ->where('log_name', HitsSearch::$log_name)
            ->first();

        $this->assertArrayHasKey('vin', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['vin']);

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals(['654321'], $activity->properties['plateNumber']);
    }

    #[Group('jobs')]
    public function test_it_logs_default_search(): void
    {
        $job = new LogFilterActivity(
            log: 'other',
            filterState: [
                'queryBuilder' => [
                    'rules' => [
                        [
                            'type' => 'ocr',
                            'data' => [
                                'settings' => [
                                    'text' => '654321',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            types: [
                'ocr' => 'plateNumber',
            ],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => DefaultLog::$log_name,
            'description' => $user->email.' searched for "other"',
        ]);

        $activity = Activity::query()
            ->where('log_name', DefaultLog::$log_name)
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals(['654321'], $activity->properties['plateNumber']);
    }

    #[Group('jobs')]
    public function test_it_logs_plate_not_on_hotlist(): void
    {
        $job = new LogFilterActivity(
            log: 'other',
            filterState: [
                'filters' => [
                    'ocr' => '123456',
                ],
            ],
            types: ['ocr' => 'plateNumber'],
            user: $user = User::factory()->create(),
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => DefaultLog::$log_name,
            'description' => $user->email.' searched for "other"',
        ]);

        $activity = Activity::query()
            ->where('log_name', DefaultLog::$log_name)
            ->first();

        $this->assertArrayHasKey('platesNotOnHotlist', $activity->properties);
        $this->assertEquals(['123456'], $activity->properties['platesNotOnHotlist']);
    }

    #[Group('jobs')]
    public function test_it_with_session_values(): void
    {
        $impersonator = User::factory()->create();
        $ip = fake()->localIpv4();
        $userAgent = fake()->userAgent();

        $job = new LogFilterActivity(
            log: 'other',
            filterState: [
                'queryBuilder' => [
                    'rules' => [
                        [
                            'type' => 'ocr',
                            'data' => [
                                'settings' => [
                                    'text' => '654321',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            types: [
                'ocr' => 'plateNumber',
            ],
            user: $user = User::factory()->create(),
            session: [
                'impersonator' => $impersonator->only('id', 'email'),
                'clientIp' => $ip,
                'userAgent' => $userAgent,
            ]
        );

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        $job->handle();

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => DefaultLog::$log_name,
            'description' => $user->email.' searched for "other"',
        ]);

        $activity = Activity::query()
            ->where('log_name', DefaultLog::$log_name)
            ->first();

        $this->assertArrayHasKey('_request', $activity->properties);
        $this->assertArrayHasKey('clientIp', $activity->properties['_request']);
        $this->assertEquals($ip, $activity->properties['_request']['clientIp']);
        $this->assertArrayHasKey('userAgent', $activity->properties['_request']);
        $this->assertEquals($userAgent, $activity->properties['_request']['userAgent']);

        $this->assertArrayHasKey('_impersonator', $activity->properties);
        $this->assertEquals($impersonator->only('id', 'email'), $activity->properties['_impersonator']);
    }
}
