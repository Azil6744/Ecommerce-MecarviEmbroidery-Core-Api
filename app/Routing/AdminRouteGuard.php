<?php

namespace App\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;

/**
 * Safety net that forces `central.auth` + `admin.only` onto admin API routes.
 *
 * Many admin routes in routes/api.php and routes/ecommerce.php were registered inside
 * groups that only authenticate (`central.auth`), optionally authenticate
 * (`central.auth:optional`) or not at all, so any customer (or anonymous visitor) could
 * call them. Rather than relying on every route being placed in the right group, this
 * runs once after the API routes are registered (and therefore also before
 * `route:cache` serialises them) and locks:
 *
 *   1. every route whose URI has an `admin` segment (e.g. api/v1/admin/..., api/admin/...,
 *      api/v1/policy-sections/admin);
 *   2. every non-GET route handled by a controller in App\Http\Controllers\Api\Admin
 *      (CMS content, settings, banners, notes, todos, ...);
 *   3. the admin-only GET actions listed in SENSITIVE_READS (form submissions, notification
 *      settings, notes/todos, staff lists, ...).
 *
 * Exceptions are listed explicitly below. Routes that already carry admin.only, and
 * personal-workspace routes (workspace.personal middleware), are left alone. To make a
 * new admin-namespace action public, add it to PUBLIC_ACTIONS.
 */
class AdminRouteGuard
{
    private const ADMIN_NS = 'App\\Http\\Controllers\\Api\\Admin\\';

    /**
     * Controllers that enforce granular Spatie permissions themselves (checkPermission /
     * isSuperAdmin) so staff with custom roles keep access. Do not add admin.only here.
     */
    private const SELF_AUTHORIZED_CONTROLLERS = [
        'UserController',
        'StaffUserController',
        'RolePermissionController',
    ];

    /**
     * Admin-namespace actions that are intentionally public or customer-facing.
     * Format: ControllerBasename@method.
     */
    private const PUBLIC_ACTIONS = [
        // Customer loyalty actions
        'EcommerceConfigController@claimDailyCheckin',
        'EcommerceConfigController@redeemPreview',
        'EcommerceConfigController@redeemReward',
        'EcommerceConfigController@transferToWallet',
        // Checkout
        'AdminShippingManagerController@calculateRates',
        // Public form submissions
        'ProductReportController@submit',
        'ContactFormSubmissionController@store',
        'CareerSupportFormSubmissionController@store',
        'ScheduleFormSubmissionController@store',
        'UserSubmittedQuestionController@store',
        // Public listings exposed under /admin/.../public
        'AdminSubscriptionPlanController@publicIndex',
        'AdminMembershipBenefitController@publicIndex',
    ];

    /** GET actions on non-admin URIs that expose admin-only data. */
    private const SENSITIVE_READS = [
        'NoteController@*',
        'TodoController@*',
        'ContactFormSubmissionController@*',
        'CareerSupportFormSubmissionController@*',
        'ScheduleFormSubmissionController@*',
        'NotificationSettingsController@*',
        'QuoteFormFieldController@getPageSettings',
        'VariableSettingController@staffOptions',
        'ProductController@generateSku',
    ];

    public static function apply(RouteCollectionInterface $routes): void
    {
        foreach ($routes->getRoutes() as $route) {
            if (self::shouldLock($route)) {
                self::lock($route);
            }
        }

        // Middleware on existing routes changed: rebuild name/action lookups.
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    public static function shouldLock(Route $route): bool
    {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/')) {
            return false;
        }

        $middleware = $route->middleware();

        // Personal workspaces (e.g. /api/v1/my/notes) are scoped to the signed-in user.
        if (in_array('workspace.personal', $middleware, true)) {
            return false;
        }

        if (in_array('admin.only', $middleware, true) || in_array(\App\Http\Middleware\AdminOnly::class, $middleware, true)) {
            return false;
        }

        [$class, $method] = self::controllerAction($route);
        $basename = $class ? class_basename($class) : null;
        $action = $basename ? $basename . '@' . $method : null;

        if ($action && in_array($action, self::PUBLIC_ACTIONS, true)) {
            return false;
        }

        $isAdminController = $class && str_starts_with($class, self::ADMIN_NS);
        if ($isAdminController && in_array($basename, self::SELF_AUTHORIZED_CONTROLLERS, true)) {
            return false;
        }

        // 1. URI with an "admin" segment.
        if (preg_match('#(^|/)admin(/|$)#', $uri)) {
            return true;
        }

        if (! $isAdminController) {
            return false;
        }

        // 2. Writes on admin controllers.
        $readOnly = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === [];
        if (! $readOnly) {
            return true;
        }

        // 3. Sensitive reads on admin controllers.
        foreach (self::SENSITIVE_READS as $pattern) {
            [$pClass, $pMethod] = explode('@', $pattern);
            if ($pClass === $basename && ($pMethod === '*' || $pMethod === $method)) {
                return true;
            }
        }

        return false;
    }

    private static function lock(Route $route): void
    {
        $existing = (array) ($route->getAction('middleware') ?? []);

        // Drop optional auth; admin access always requires a real token.
        $existing = array_values(array_filter(
            $existing,
            fn ($m) => ! (is_string($m) && str_starts_with($m, 'central.auth'))
        ));

        // Keep the group middleware (e.g. "api") first, then authenticate, then authorise.
        $route->setAction(array_merge($route->getAction(), [
            'middleware' => array_merge($existing, ['central.auth', 'admin.only']),
        ]));
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function controllerAction(Route $route): array
    {
        $uses = $route->getAction('uses');
        if (! is_string($uses) || ! str_contains($uses, '@')) {
            return [null, null];
        }

        [$class, $method] = explode('@', $uses, 2);

        return [ltrim($class, '\\'), $method];
    }
}
