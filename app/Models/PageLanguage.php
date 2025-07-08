<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Itstructure\Mult\Models\Language;

/**
 * Class PageLanguage
 *
 * @property Page $page
 * @property Language $language
 *
 * @package App\Models
 */
class PageLanguage extends Model
{
    protected $table = 'pages_languages';

    protected $fillable = ['pages_id', 'languages_id', 'title', 'description', 'content', 'meta_keys', 'meta_description'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function page()
    {
        return $this->hasOne(Page::class, 'id', 'pages_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function language()
    {
        return $this->hasOne(Language::class, 'id', 'languages_id');
    }
}
