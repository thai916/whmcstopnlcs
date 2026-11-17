<?php

// Minimal framework harness: no bootstrap/app.php, application providers, .env,
// MySQL connection, queue worker, mail transport, or production view cache.
use App\Http\Controllers\Admin\WhmcsHistoryController;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Services\WhmcsImport\HistoryStore;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

$root = dirname(__DIR__, 2);
$temporary = (getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA').'/Temp/opencode' : sys_get_temp_dir()).'/whmcs-profile-'.bin2hex(random_bytes(6));
mkdir($temporary.'/admin/layouts', 0700, true);
mkdir($temporary.'/compiled', 0700);
file_put_contents($temporary.'/admin/layouts/app.blade.php', '<!doctype html><title>@yield("title")</title>@yield("content")');
try {
    $app = new Application($root);
    $app->useLangPath($root.'/lang');
    $app->instance('config', new Repository([
        'app' => ['env' => 'testing', 'locale' => 'en', 'fallback_locale' => 'en', 'url' => 'http://history.test'],
        'view' => ['paths' => [$temporary, $root.'/resources/views'], 'compiled' => $temporary.'/compiled'],
        'session' => ['driver' => 'array'],
    ]));
    $app->instance('files', new Filesystem);
    $app->instance('events', new Dispatcher($app));
    $app->instance('db', $db->getDatabaseManager());
    \Illuminate\Database\Eloquent\Model::setConnectionResolver($db->getDatabaseManager());
    $connection->getSchemaBuilder()->create('whmcs_import_connections', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('database');
    });
    $app->instance(HistoryStore::class, $store);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);
    $request = Request::create('http://history.test/admin/clients/1001/whmcs-history');
    $session = new SessionStore('history-tests', new ArraySessionHandler(120));
    $session->start();
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    $app->instance('session', $session);
    $app->instance('session.store', $session);
    $guard = new \Illuminate\Auth\RequestGuard(fn () => null, $request);
    $auth = new class ($guard) implements AuthFactory
    {
        public function __construct(private object $guard)
        {
        }

        public function guard($name = null)
        {
            return $this->guard;
        }

        public function shouldUse($name)
        {
        }
    };
    $app->instance(AuthFactory::class, $auth);
    $app->instance('auth', $auth);
    $app->register(\Illuminate\Routing\RoutingServiceProvider::class);
    $app->register(\Illuminate\View\ViewServiceProvider::class);
    $app->register(\Illuminate\Translation\TranslationServiceProvider::class);
    $app->register(\Illuminate\Validation\ValidationServiceProvider::class);
    (new \Illuminate\Foundation\Providers\FoundationServiceProvider($app))->registerRequestValidation();
    require $root.'/routes/admin.php';
    $app['router']->getRoutes()->refreshNameLookups();
    $app['view']->share('errors', new ViewErrorBag);
    $attachmentService = new \App\Services\WhmcsImport\HistoryAttachments(
        $store, $connection, new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'), $temporary.'/private',
    );
    $controller = new WhmcsHistoryController($store, $attachmentService);
    $client = new Client;
    $client->forceFill(['id' => 1001, 'first_name' => 'Synthetic', 'last_name' => 'Customer']);
    $assertStatus = function (callable $call, int $status, string $label) {
        try {
            $call();
            check(false, $label);
        } catch (HttpException $e) {
            check($e->getStatusCode() === $status, $label);
        }
    };
    $assertStatus(fn () => $controller->client($request, $client), 403, 'guest cannot invoke customer archive controller');
    $admin = new Admin;
    $admin->forceFill(['id' => 9, 'is_disabled' => false]);
    $role = new AdminRole(['is_full_admin' => false, 'permissions' => ['view_clients', 'manage_settings']]);
    $admin->setRelation('role', $role);
    $guard->setUser($admin);
    $assertStatus(fn () => $controller->client($request, $client), 403, 'ordinary staff permissions do not grant access to internal archive notes');
    $assertStatus(fn () => $controller->index(), 403, 'ordinary staff cannot access historical import console');
    $role->is_full_admin = true;
    $admin->is_disabled = true;
    $assertStatus(fn () => $controller->client($request, $client), 403, 'disabled full administrator cannot access archives');
    $admin->is_disabled = false;
    $response = $controller->client($request, $client);
    check($response->getStatusCode() === 200 && str_contains($response->getContent(), 'WHMCS historical archive'), 'authorized customer archive renders actual Blade view');
    check(str_contains($response->headers->get('Cache-Control'), 'no-store'), 'sensitive archive pages disable response caching');
    $response = $controller->record($client, (int) $store->find('synthetic', 'tbltickets', '161')->id);
    check(str_contains($response->getContent(), '&lt;script&gt;') && ! str_contains($response->getContent(), '<script>'), 'actual archive detail view escapes imported scripts');
    $response = $controller->record($client, (int) $store->find('synthetic', 'tblticketnotes', '181')->id);
    check(str_contains($response->getContent(), 'Internal only'), 'full administrator can read scoped internal ticket note');
    $other = new Client;
    $other->forceFill(['id' => 1002]);
    $assertStatus(fn () => $controller->record($other, (int) $invoice->id), 404, 'record controller prevents cross-customer archive ID access');
    $response = $controller->record($client, (int) $invoice->id);
    check(str_contains($response->getContent(), 'INV-2015/0001') && str_contains($response->getContent(), '100.00'), 'invoice detail renders linked exact financial evidence');
    $response = $controller->index();
    check($response->getStatusCode() === 200 && str_contains($response->getContent(), 'Recent historical runs'), 'historical console renders actual Blade view and persisted run reports');
    $filtered = Request::create('http://history.test/admin/clients/1001/whmcs-history?category=invoices&source=synthetic');
    $app->instance('request', $filtered);
    $response = $controller->client($filtered, $client);
    check(str_contains($response->getContent(), 'tblinvoices') && ! str_contains($response->getContent(), '<td>tbltickets</td>'), 'profile category filter limits displayed records');
    $badFilter = Request::create('http://history.test/admin/clients/1001/whmcs-history?category=unknown');
    rejects(fn () => $controller->client($badFilter, $client), 'invalid category filter is rejected instead of falling back to clients');

    mkdir($temporary.'/restored', 0700);
    file_put_contents($temporary.'/restored/http.bin', 'Synthetic download bytes.');
    $httpMapping = [
        'record_id' => (int) $fileRecord->id, 'field' => 'filename', 'relative_path' => 'http.bin',
        'reference_hash' => hash('sha256', 'private-contract.pdf'),
        'sha256' => hash_file('sha256', $temporary.'/restored/http.bin'), 'download_name' => 'download.txt',
    ];
    $httpAttachment = $attachmentService->transfer($httpMapping, $temporary.'/restored', true, false);
    $downloadResponse = $controller->attachment($client, (int) $fileRecord->id, $httpAttachment['attachment_id']);
    check($downloadResponse->headers->get('Content-Type') === 'application/octet-stream'
        && str_contains($downloadResponse->headers->get('Content-Disposition'), 'attachment;'), 'authorized attachment download forces binary attachment disposition');
    check($downloadResponse->headers->get('X-Content-Type-Options') === 'nosniff'
        && str_contains($downloadResponse->headers->get('Cache-Control'), 'no-store'), 'attachment downloads disable type sniffing and response caching');
    $assertStatus(fn () => $controller->attachment($other, (int) $fileRecord->id, $httpAttachment['attachment_id']), 404, 'attachment controller denies cross-customer download');
    $assertStatus(fn () => $controller->attachment($client, (int) $invoice->id, $httpAttachment['attachment_id']), 404, 'attachment controller denies a mismatched parent record');
    $role->is_full_admin = false;
    $assertStatus(fn () => $controller->attachment($client, (int) $fileRecord->id, $httpAttachment['attachment_id']), 403, 'ordinary staff cannot download historical attachments');
    $role->is_full_admin = true;

    $source->data = $fixture;
    $source->data['tblemails'] = [];
    foreach (range(1, 31) as $id) {
        $source->data['tblemails'][] = ['id' => $id, 'userid' => 41, 'subject' => 'Synthetic pagination', 'message' => 'History only'];
    }
    $importer->run($source, 'pagination', 'snapshot', ['clients', 'emails'], false);
    $store->linkClient('pagination', '41', 1006, 9);
    $pagedClient = new Client;
    $pagedClient->forceFill(['id' => 1006, 'first_name' => 'Paged', 'last_name' => 'Customer']);
    $pageOne = $controller->client(Request::create('/admin/clients/1006/whmcs-history'), $pagedClient)->getOriginalContent()->getData();
    check(count($pageOne['records']) === 25 && $pageOne['next'] !== null, 'profile archive uses bounded keyset pagination');
    $pageTwo = $controller->client(Request::create('/admin/clients/1006/whmcs-history?after='.$pageOne['next']), $pagedClient)->getOriginalContent()->getData();
    check(count($pageTwo['records']) === 7 && $pageTwo['next'] === null
        && array_intersect(array_column($pageOne['records'], 'id'), array_column($pageTwo['records'], 'id')) === [], 'archive next page neither skips nor duplicates records');
    $source->data = $fixture;

    $app->instance(\App\Services\WhmcsImport\HistoryAttachments::class, $attachmentService);
    file_put_contents($temporary.'/manifest.json', json_encode([$httpMapping], JSON_THROW_ON_ERROR));
    $command = new \App\Console\Commands\WhmcsHistoryAttachments;
    $command->setLaravel($app);
    $output = new \Symfony\Component\Console\Output\BufferedOutput;
    $beforeManifests = $connection->table('whmcs_history_attachments')->count();
    $exit = $command->run(new \Symfony\Component\Console\Input\ArrayInput([
        'manifest' => $temporary.'/manifest.json', '--source-root' => $temporary.'/restored',
        '--verified-storage' => true,
    ]), $output);
    $text = $output->fetch();
    check($exit === 0 && $connection->table('whmcs_history_attachments')->count() === $beforeManifests, 'attachment console command defaults to a non-writing dry run');
    check(! str_contains($text, 'http.bin') && ! str_contains($text, $temporary), 'attachment command output does not disclose paths or original names');

    $route = $app['router']->getRoutes()->getByName('admin.clients.whmcs-history');
    check(in_array('admin.auth', $route->middleware(), true) && in_array('admin.2fa', $route->middleware(), true), 'actual archive routes inherit admin login and two-factor middleware');
    check(in_array('admin.permission:view_clients', $route->middleware(), true), 'archive route also retains existing customer-view permission');
    $linkRoute = $app['router']->getRoutes()->getByName('admin.clients.whmcs-history.link');
    check($linkRoute->methods() === ['POST'], 'customer adoption cannot be performed by GET');
    $runRoute = $app['router']->getRoutes()->getByName('admin.whmcs-import.history.run');
    check(in_array('admin.permission:manage_settings', $runRoute->middleware(), true), 'historical import route retains settings permission');

    foreach (glob($root.'/resources/views/admin/whmcs-history/*.blade.php') as $template) {
        token_get_all($app['blade.compiler']->compileString(file_get_contents($template)), TOKEN_PARSE);
        check(true, 'Blade compiles: '.basename($template));
    }
    $connection->getSchemaBuilder()->drop('whmcs_history_owners');
    $response = $controller->client(Request::create('/admin/clients/1001/whmcs-history'), $client);
    check($response->getStatusCode() === 200 && str_contains($response->getContent(), 'Historical archive tables are not installed'), 'customer archive fails closed with a useful missing-schema notice');
} finally {
    // Only this test's uniquely named temporary tree; never application storage.
    (new Filesystem)->deleteDirectory($temporary);
}
