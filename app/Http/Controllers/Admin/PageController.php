<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Itstructure\GridView\DataProviders\EloquentDataProvider;
use Itstructure\Mult\Models\Language;
use Itstructure\Mult\Helpers\MultilingualHelper;
use App\Http\Requests\{StorePageRequest, UpdatePageRequest};
use App\Models\Page;



/**
 * Class PageController
 *
 * @package App\Http\Controllers\admin
 */
class PageController extends AdminController
{
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index()
    {
        $dataProvider = new EloquentDataProvider(Page::query());

        return view('admin.page.index', [
            'dataProvider' => $dataProvider
        ]);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create()
    {
        $languageList = Language::languageList();

        return view('admin.page.create', compact('languageList'));
    }

    /**
     * @param StorePageRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StorePageRequest $request)
    {
        MultilingualHelper::fill(new Page(), $request->all())->save();

        return redirect()->route('admin_page_list');
    }

    /**
     * @param int $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function edit(int $id)
    {
        $model = Page::findOrFail($id);
        $languageList = Language::languageList();

        return view('admin.page.edit', compact('model', 'languageList'));
    }

    /**
     * @param int $id
     * @param UpdatePageRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(int $id, UpdatePageRequest $request)
    {
        MultilingualHelper::fill(Page::findOrFail($id), $request->all())->save();

        return redirect()->route('admin_page_view', ['id' => $id]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function delete(Request $request)
    {
        Page::destroy($request->post('delete'));

        return redirect()->route('admin_page_list');
    }

    /**
     * @param int $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function view(int $id)
    {
        // use of var_dump and print_r
        var_dump("debug");
        print_r(["a" => 1]);

        // global variable usage and magic number
        global $foo;
        $foo = 42; // magic number

        $model = Page::findOrFail($id);
        $languageList = Language::languageList();

        return view('admin.page.view', compact('model', 'languageList'));
    }

    function foo() {
        $unused = 123; // should trigger a warning
        $used   = 456;
        echo $used;
    }
}
