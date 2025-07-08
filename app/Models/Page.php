<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Itstructure\Mult\Traits\MultilingualModelTrait;

/**
 * Class Page
 *
 * @package App\Models
 */
class Page extends Model
{
    use MultilingualModelTrait;

    protected $table = 'pages';

    protected $fillable = ['parent_id', 'active', 'icon'];
}
