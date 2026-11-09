<?php

namespace App\Services\Evaluation;

use Mpdf\Language\LanguageToFont;

class ReportLanguageToFont extends LanguageToFont
{
    public function getLanguageOptions($llcc, $adobeCJK)
    {
        if (strtolower(explode('-', $llcc)[0]) === 'bn') {
            return [false, 'nikosh'];
        }

        return parent::getLanguageOptions($llcc, $adobeCJK);
    }
}
