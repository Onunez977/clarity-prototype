<?php

namespace App\View\Components\Scans;

use Illuminate\View\Component;
use Illuminate\View\View;

class DispositionPopup extends Component
{
    public function __construct(
        public array $reasons = ['Not a Plate', 'Plate Too Blurry', 'Wrong OCR', 'Wrong Plate State', 'Other'],
    ) {
        //
    }

    public function render(): View
    {
        return view('components.scans.disposition-popup');
    }
}
