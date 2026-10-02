<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Host Key
    |--------------------------------------------------------------------------
    |
    | Whoever enters this key on /host runs the game: starts rounds, opens
    | voting, reveals results, and edits prompts. Leave it empty and nobody
    | can host.
    |
    */

    'host_key' => env('HOST_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Round Timers
    |--------------------------------------------------------------------------
    |
    | Countdowns shown to the room, in seconds. They are only a nudge: the
    | host decides when each phase actually ends.
    |
    */

    'write_seconds' => 90,

    'vote_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Avatars
    |--------------------------------------------------------------------------
    |
    | What players can pick when they join, keyed by a short name. Each value
    | is either an emoji or the path of an image in public/, for example
    | '/avatars/flamingo.png'.
    |
    */

    'avatars' => [
        'flamingo' => '🦩',
        'house' => '🏡',
        'cactus' => '🌵',
        'key' => '🔑',
        'coffee' => '☕',
        'wrench' => '🔧',
        'palm' => '🌴',
        'turtle' => '🐢',
        'owl' => '🦉',
        'spider' => '🕷️',
        'sun' => '🌞',
        'bee' => '🐝',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Prompts
    |--------------------------------------------------------------------------
    |
    | A new game starts with these. The host can change them from the lobby.
    | Each round trains one value from the Value Pyramid: a situation, an
    | optional quoted line, a question, the value's name, and the point the
    | presenter makes about it once the votes are revealed.
    |
    */

    'prompts' => [
        [
            'value' => 'Total Effort',
            'setup' => 'How can you tell total effort apart from just looking busy?',
            'line' => '',
            'ask' => 'Answer in one sentence.',
            'point' => 'Total effort is measured by what you put in, whether or not anyone was watching.',
        ],
        [
            'value' => 'Eat Your Spider',
            'setup' => 'What is the best way to get started on a task you keep putting off?',
            'line' => '',
            'ask' => 'Answer in one sentence.',
            'point' => 'Do the hardest thing first. It only gets bigger while it waits, and everything after it is easier.',
        ],
        [
            'value' => 'Empathic Listening',
            'setup' => 'What is the hardest part of really listening to someone?',
            'line' => '',
            'ask' => 'Answer in one sentence.',
            'point' => 'Understand before you try to be understood. People open up when they feel heard, not when they are corrected.',
        ],
        [
            'value' => 'Goals',
            'setup' => 'Finish the sentence:',
            'line' => 'Most goals quietly fade because…',
            'ask' => 'Finish it in your own words.',
            'point' => "A goal has a number and a date, and someone else knows about it. Until then it's a wish.",
        ],
        [
            'value' => 'Sharpen the Saw',
            'setup' => 'Finish the sentence:',
            'line' => 'I do my best work after I…',
            'ask' => 'Finish it in your own words.',
            'point' => 'Taking care of yourself and learning new things are part of doing the job well. A sharp saw cuts faster.',
        ],
        [
            'value' => 'Q2',
            'setup' => 'When everything feels urgent, how do you decide what not to do?',
            'line' => '',
            'ask' => 'Answer in one sentence.',
            'point' => 'Q2 is the important work that never feels urgent. It only happens when you protect time for it.',
        ],
        [
            'value' => 'Definitive Purpose',
            'setup' => 'Finish the sentence:',
            'line' => 'A year from now, I would be proud to say our team…',
            'ask' => 'Finish it in your own words.',
            'point' => 'A definite purpose is one specific aim you have chosen. Once you can name it, it is clear what deserves your time.',
        ],
        [
            'value' => 'Maximize the Yield',
            'setup' => 'What is one small habit that makes a big difference over a year?',
            'line' => '',
            'ask' => 'Answer in one sentence.',
            'point' => 'Yield comes from small things done well every day more than from big moves.',
        ],
        [
            'value' => 'High Trust with Our Customers',
            'setup' => 'How do you tell a resident no and have them trust you more afterward?',
            'line' => '',
            'ask' => 'Answer in one sentence.',
            'point' => 'Trust comes from being fair and consistent, and from explaining a no with respect.',
        ],
        [
            'value' => 'High Trust & Loyalty with All Employees',
            'setup' => 'Last one. Finish the sentence:',
            'line' => 'I trust a teammate most when they…',
            'ask' => 'Finish it in your own words.',
            'point' => 'Trust is built in small moments: giving credit, owning a miss, and saying it to the person instead of about them.',
        ],
    ],

];
