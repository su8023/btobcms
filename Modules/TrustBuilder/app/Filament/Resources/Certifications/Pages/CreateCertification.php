<?php

namespace Modules\TrustBuilder\Filament\Resources\Certifications\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\TrustBuilder\Filament\Resources\CertificationResource;

class CreateCertification extends CreateRecord
{
    protected static string $resource = CertificationResource::class;
}
