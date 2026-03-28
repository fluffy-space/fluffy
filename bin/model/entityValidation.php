<?php

namespace Components\Models\SubFolder;

use SharedPaws\Validation\IValidationRules;
use SharedPaws\Validation\ValidationRules;

class EntityNameValidation implements IValidationRules
{
    public function __construct(private EntityNameModel $model)
    {
    }

    public function getValidationRules(): array
    {
        return ValidationRules::rules($this->model)
            ->toList();
    }
}
