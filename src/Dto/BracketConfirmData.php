<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The fixed half of a confirmed import.
 *
 * The decisions and the finishing order are a variable-length answer set keyed
 * by names the server produced, so they are read off the request rather than
 * bent into form fields. What is left is three things of fixed shape: which
 * bracket is being confirmed, who won the cut, and the passphrase — which is
 * checked here and not at the fetch, because the fetch writes nothing.
 *
 * The winner is nullable because "nobody" is a real answer and it is the
 * placeholder: a bracket with no cut has no winner, and so does one whose cut
 * was never finished. A `ChoiceType` renders that absence as null however hard
 * `empty_data` is leaned on, and pretending otherwise costs a 500.
 */
final class BracketConfirmData
{
    public function __construct(
        public string $slug = '',
        public ?string $knockoutWinner = null,
        public string $passphrase = '',
    ) {
    }
}
