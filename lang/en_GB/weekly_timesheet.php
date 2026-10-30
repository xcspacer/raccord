<?php

return [
    'sheet' => 'Time sheet W:week',
    'title' => 'WEEKLY TIME SHEET — TEAM :team',
    'week' => 'Week :week · from :from to :to',
    'columns' => [
        'week' => 'Week',
        'date' => 'Date',
        'technician' => 'Technician',
        'work' => 'Work done',
        'start' => 'Start of day',
        'end' => 'End of day',
    ],
    'departed' => 'Left the company',
    'note' => 'Note: :note',
    'in_progress' => 'In progress',
    'not_finished' => 'Not finished',
    'generated' => 'Generated on :date by :user · from the :app planning (tasks, absences and punches of the technicians; * = end time declared by the technician).',
    'location_notice' => 'Location data — internal use only. ⚠ = position less precise than :metres m.',
    'file' => 'weekly-time-sheet-W:week-:monday',
    'button' => 'Time sheet (Excel)',
];
