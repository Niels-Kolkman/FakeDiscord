<?php

namespace App\Http\Controllers;

use App\Actions\Channels\CreateCategory;
use App\Actions\Channels\DeleteCategory;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\ChannelCategory;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Create, rename and delete channel categories (ServerPolicy::manageChannels).
 */
class ChannelCategoryController extends Controller
{
    public function create(Server $server): View
    {
        Gate::authorize('manageChannels', $server);

        return view('categories.create', ['server' => $server]);
    }

    public function store(StoreCategoryRequest $request, Server $server, CreateCategory $categories): RedirectResponse
    {
        $category = $categories->create($server, $request->string('name')->value());

        return redirect()
            ->route('servers.show', $server)
            ->with('status', "The category \"{$category->name}\" has been created.");
    }

    public function edit(Server $server, ChannelCategory $category): View
    {
        Gate::authorize('manageChannels', $server);

        return view('categories.edit', ['server' => $server, 'category' => $category]);
    }

    public function update(StoreCategoryRequest $request, Server $server, ChannelCategory $category): RedirectResponse
    {
        $category->update(['name' => $request->string('name')->value()]);

        return redirect()
            ->route('categories.edit', [$server, $category])
            ->with('status', 'Category saved.');
    }

    /**
     * The category's channels are kept and become uncategorised.
     */
    public function destroy(Server $server, ChannelCategory $category, DeleteCategory $categories): RedirectResponse
    {
        Gate::authorize('manageChannels', $server);

        $categories->delete($category);

        return redirect()
            ->route('servers.show', $server)
            ->with('status', "The category \"{$category->name}\" has been deleted.");
    }
}
