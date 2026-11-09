<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationRouteRequest;
use App\Models\OrganizationRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationRouteController extends Controller
{
    public function index(): Response
    {
        $routes = $this->organizationRoutes()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('routes/Index', [
            'routes' => $routes->map(fn (OrganizationRoute $route) => $route->toRecord()),
            'home_port' => auth()->user()?->organization?->homePort() ?? '',
            'stats' => [
                'total' => $routes->count(),
                'active' => $routes->where('is_active', true)->count(),
                'regular' => $routes->filter(
                    fn (OrganizationRoute $route) => $route->isRegularTrip(),
                )->count(),
                'exclusive' => $routes->filter(
                    fn (OrganizationRoute $route) => $route->isExclusiveBoatRental(),
                )->count(),
            ],
        ]);
    }

    public function store(StoreOrganizationRouteRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $organizationId = $request->user()?->organization_id;

        $nextOrder = ((int) $this->organizationRoutes()->max('sort_order')) + 1;

        OrganizationRoute::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'sort_order' => $nextOrder,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Route saved for your organization.']);

        return to_route('routes.index');
    }

    public function update(StoreOrganizationRouteRequest $request, OrganizationRoute $organizationRoute): RedirectResponse
    {
        $this->authorizeRoute($organizationRoute);

        $organizationRoute->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Route updated.']);

        return to_route('routes.index');
    }

    public function updateStatus(Request $request, OrganizationRoute $organizationRoute): RedirectResponse
    {
        $this->authorizeRoute($organizationRoute);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $organizationRoute->update(['is_active' => $validated['is_active']]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['is_active']
                ? 'Route activated.'
                : 'Route deactivated.',
        ]);

        return back();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<OrganizationRoute>
     */
    private function organizationRoutes()
    {
        return OrganizationRoute::query();
    }

    private function authorizeRoute(OrganizationRoute $route): void
    {
        abort_unless($route->organization_id === auth()->user()?->organization_id, 404);
    }
}
