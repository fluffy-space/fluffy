<?php

namespace Components\Models\SubFolder;

use SharedPaws\Models\BaseModel;

class EntityNameModel extends BaseModel
{
    public ?string $Title = null;
    public bool $Published = false;
    public ?int $PictureId = null;
    public ?string $PicturePath = null;
}
