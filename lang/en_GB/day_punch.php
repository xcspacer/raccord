<?php

return [
    'errors' => [
        'no_open_day' => 'No day in progress to finish.',
        'previous_day_open' => 'The previous day is not finished: declare its end first.',
        'end_before_start' => 'The end time must be after the start and not in the future.',
        'too_long' => 'A day cannot last more than 30 hours.',
    ],
    'gate' => [
        'title' => 'Start the day',
        'hint' => 'Your position is recorded at the moment you start, only to confirm your presence. If location is refused, the day is recorded without a position.',
        'privacy' => 'Only you and your managers can see it. It is erased after :months months; the times are kept.',
        'start' => 'Start the day',
    ],
    'end' => [
        'button' => 'Finish the day',
        'started_at' => 'Started: :time',
        'declare_reminder' => 'Declare the end of :date',
    ],
    'declare' => [
        'title' => 'Declare the end',
        'text' => 'The day of :date, started at :time, is not finished. Enter the time it ended.',
        'time' => 'End time',
        'submit' => 'Declare the end',
        'cancel' => 'Cancel',
    ],
    'toast' => [
        'declared' => 'End of day declared.',
    ],
    'js' => [
        'locating' => 'Locating…',
        'started' => 'Day started at :time.',
        'ended' => 'Day finished at :time.',
        'queued' => 'No network: the record will be sent as soon as the connection is back.',
        'network_error' => 'Could not save. Try again.',
        'end_confirm' => 'Finish the day started at :time?',
        'denied_hint' => 'Location refused: the day is recorded without a position.',
    ],
    'pin' => [
        'start' => 'Start',
        'end' => 'End',
        'declared' => 'End declared by the person (no position)',
        'no_position' => 'Position unavailable',
        'open_map' => 'Open the position',
        'running' => 'In progress',
        'missing_end' => 'End not recorded',
        'imprecise' => 'Imprecise position (± :metres m)',
    ],
];
