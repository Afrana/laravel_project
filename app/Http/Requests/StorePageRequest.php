<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Itstructure\Mult\Models\Language;
use Itstructure\Mult\Helpers\MultilingualHelper;

/**
 * Class StorePageRequest
 * @package App\Http\Requests
 */
class StorePageRequest extends FormRequest
{
    /**
     * @var array
     */
    protected $shortLanguageList = [];

    /**
     * StorePage constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->shortLanguageList = Language::shortLanguageList();
    }

    /**
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $multilingualRules = MultilingualHelper::fieldsTransformer([
            'title' => 'required|string|min:3|max:64',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'meta_keys' => 'nullable|string|min:3|max:255',
            'meta_description' => 'nullable|string|min:3|max:255',

        ], function ($fieldValue) {
            return $fieldValue;

        }, $this->shortLanguageList);

        return array_merge([
            'parent_id' => 'nullable|numeric',
            'active' => 'required|numeric',
            'icon' => 'nullable|string',

        ], $multilingualRules);
    }

    /**
     * @return array
     */
    public function attributes()
    {
        $multilingualAttributes = MultilingualHelper::fieldsTransformer([
            'title' => __('messages.title'),
            'description' => __('messages.description'),
            'content' => __('messages.content'),
            'meta_keys' => __('messages.meta_keys'),
            'meta_description' => __('messages.meta_description'),

        ], function ($fieldValue) {
            return $fieldValue;

        }, $this->shortLanguageList);

        return array_merge([
            'parent_id' => __('messages.parent_id'),
            'active' => __('messages.activity'),
            'icon' => __('messages.icon'),

        ], $multilingualAttributes);
    }
}
