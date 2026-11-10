@include('viva.imports.report', ['boards' => collect([$board->setRelation('candidates', $candidates)])])
