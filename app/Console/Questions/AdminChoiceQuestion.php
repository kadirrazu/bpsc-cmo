<?php

namespace App\Console\Questions;

use Symfony\Component\Console\Question\ChoiceQuestion;

class AdminChoiceQuestion extends ChoiceQuestion
{
    public function __construct(string $question, array $choices, mixed $default = null)
    {
        parent::__construct($question, $choices, $default);

        // Numeric selection needs no raw terminal mode. In Windows shells with
        // Git's stty, autocomplete cleanup may redirect to the Unix /dev/null.
        $this->setAutocompleterValues(null);
    }
}
