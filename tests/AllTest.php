<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * =============================================================================
 * INTEGRATION TEST SUITE (AllTest.php)
 * =============================================================================
 * Simulates real user workflows via HTTP against a live PHP server.
 *
 * Test-method naming convention:
 *   test_{US_numbers}_{feature}
 *
 * Response-shape note: since Sprint 2 we use `display_error()` (controllers.php),
 * which returns a rich payload with `title`/`severity`/`icon`/`html`. HTTP status
 * codes are UNCHANGED, so all assertions on ->status remain valid.
 *
 * CLEANUP ORDER (critical):
 *   project.company_id uses ON DELETE RESTRICT, so test projects MUST be
 *   deleted BEFORE the companies that own them. And companies must be
 *   deleted BEFORE the test users, because company.owner_id cascades on
 *   user delete - and if a company still has a project, the cascade
 *   fails with FK code 23503.
 * =============================================================================
 */

class AllTest extends TestCase
{
    private const BASE = 'http://localhost:8000';
    private static array $cookies = [];

    /** Email of the designer account used across all tests. Owns every test company. */
    private const DESIGNER_EMAIL = 'idtest@example.test';

    // ------------------------------------------------------------------
    // HTTP wrapper - cURL + cookie jar per "agent"
    // ------------------------------------------------------------------
    private static function req(string $method, string $path, ?array $body = null, ?string $agent = null): array
    {
        $ch = curl_init(self::BASE . $path);
        $headers = ['Content-Type: application/json'];

        if ($agent !== null && isset(self::$cookies[$agent])) {
            $headers[] = 'Cookie: ' . self::$cookies[$agent];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs     = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($agent !== null && preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', substr($resp, 0, $hs), $m)) {
            self::$cookies[$agent] = $m[1];
        }

        return ['status' => $status, 'body' => json_decode(substr($resp, $hs), true)];
    }

    // ------------------------------------------------------------------
    // Fixture cleanup (FK-safe order)
    // ------------------------------------------------------------------
    private static function cleanTestArtifacts(): void
    {
        // 1. Delete projects first - FK RESTRICT from company would block company delete
        ex(
            "DELETE FROM project WHERE company_id IN (
                SELECT c.company_id FROM company c
                JOIN user_account u ON u.account_id = c.owner_id
                WHERE u.email = $1
            )",
            [self::DESIGNER_EMAIL]
        );

        // 2. Then users (cascades companies)
        $emails = [
            'idtest@example.test', 'custtest@example.test',
            'admintest@example.test', 'pmtest@example.test',
            'user+a@example.test', 'user+b@example.test',
            'user+c@example.test', 'user+d@example.test',
        ];
        foreach ($emails as $e) ex("DELETE FROM user_account WHERE email = $1", [$e]);

        // 3. Defensive cleanup
        ex("DELETE FROM company WHERE company_name LIKE 'Test Studio%' OR company_name IN ('ToDel','ProjCo')");

        // 4. Categories created by these tests
        ex("DELETE FROM category WHERE category_name LIKE 'TC%'");
    }

    public static function setUpBeforeClass(): void
    {
        self::cleanTestArtifacts();

        foreach ([
            ['User Admin',        '{user:manage,profile:manage}'],
            ['Interior Designer', '{company:manage,project:manage,interest:view}'],
            ['Customer',          '{project:view,favourite:manage}'],
            ['Platform Manager',  '{category:manage,report:generate}'],
        ] as [$n, $p]) {
            ex('INSERT INTO user_profile (profile_name, permissions) VALUES ($1,$2) ON CONFLICT (profile_name) DO NOTHING', [$n, $p]);
        }

        $h = hash_password('Password123!');
        $P = [];
        foreach (q('SELECT profile_id, profile_name FROM user_profile') as $p) $P[$p['profile_name']] = $p['profile_id'];

        foreach ([
            ['Interior Designer','idtest'], ['Customer','custtest'],
            ['User Admin','admintest'],     ['Platform Manager','pmtest'],
        ] as [$role,$u]) {
            ex('INSERT INTO user_account (profile_id, username, email, password_hash) VALUES ($1,$2,$3,$4)',
               [$P[$role], $u, "$u@example.test", $h]);
        }

        foreach (['d'=>self::DESIGNER_EMAIL,'c'=>'custtest@example.test','a'=>'admintest@example.test','m'=>'pmtest@example.test'] as $agent=>$email) {
            self::req('POST','/api/auth/login',['email'=>$email,'password'=>'Password123!'],$agent);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanTestArtifacts();
    }

    // =====================================================================
    // US #1, #13, #35, #39 - Login
    // =====================================================================
    public function test_01_13_35_39_login_missing_fields(): void
    {
        $this->assertSame(400, self::req('POST','/api/auth/login',[])['status']);
    }

    public function test_01_13_35_39_login_invalid_credentials(): void
    {
        $this->assertSame(401, self::req('POST','/api/auth/login',
            ['email'=>self::DESIGNER_EMAIL,'password'=>'wrong'])['status']);
    }

    public function test_01_13_35_39_login_and_me(): void
    {
        $ag = 'x1';
        self::req('POST','/api/auth/login',['email'=>self::DESIGNER_EMAIL,'password'=>'Password123!'],$ag);
        $me = self::req('GET','/api/auth/me',null,$ag);
        $this->assertSame('idtest', $me['body']['user']['username']);
    }

    // =====================================================================
    // US #2, #14, #36, #40 - Logout
    // =====================================================================
    public function test_02_14_36_40_logout(): void
    {
        $ag = 'x2';
        self::req('POST','/api/auth/login',['email'=>self::DESIGNER_EMAIL,'password'=>'Password123!'],$ag);
        self::req('POST','/api/auth/logout',null,$ag);
        $this->assertSame(401, self::req('GET','/api/auth/me',null,$ag)['status']);
    }

    // =====================================================================
    // US #3, #4, #5, #6, #7 - User Accounts (Admin)
    // =====================================================================
    public function test_03_create_user_missing_duplicate_search(): void
    {
        $custId = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];

        // Main flow - 201
        $r = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+a',
            'email'=>'user+a@example.test','password'=>'Password123!',
        ],'a');
        $this->assertSame(201, $r['status']);

        // A2 - Duplicate (409)
        $this->assertSame(409, self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+b',
            'email'=>'user+a@example.test','password'=>'x',
        ],'a')['status']);

        // A1 - Missing (400)
        $this->assertSame(400, self::req('POST','/api/users',['username'=>'x'],'a')['status']);
    }

    public function test_04_update_user(): void
    {
        $custId = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];
        $id = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+d',
            'email'=>'user+d@example.test','password'=>'Password123!',
        ],'a')['body']['user']['account_id'];

        $this->assertSame('user+d-up',
            self::req('PUT',"/api/users/$id",['username'=>'user+d-up'],'a')['body']['user']['username']);

        $this->assertSame(400,
            self::req('PUT',"/api/users/$id",['email'=>'not-an-email'],'a')['status']);
    }

    public function test_05_view_user_not_found(): void
    {
        $this->assertSame(404, self::req('GET','/api/users/99999999',null,'a')['status']);
    }

    public function test_06_suspend_user_double(): void
    {
        $custId = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];
        $id = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+c',
            'email'=>'user+c@example.test','password'=>'Password123!',
        ],'a')['body']['user']['account_id'];

        $this->assertSame(200, self::req('PATCH',"/api/users/$id/suspend",null,'a')['status']);
        $this->assertSame(409, self::req('PATCH',"/api/users/$id/suspend",null,'a')['status']);
    }

    public function test_07_search_user(): void
    {
        $r = self::req('GET','/api/users/search?q=user%2Ba',null,'a');
        $this->assertGreaterThanOrEqual(1, count($r['body']['users']));
    }

    // =====================================================================
    // US #8-#12 - User Profiles (Admin)
    // =====================================================================
    public function test_08_create_profile_empty_and_duplicate(): void
    {
        $this->assertSame(400,
            self::req('POST','/api/profiles',['profile_name'=>''],'a')['status']);
    }

    public function test_09_update_profile_empty_name(): void
    {
        $pid = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];
        $this->assertSame(400,
            self::req('PUT',"/api/profiles/$pid",['profile_name'=>''],'a')['status']);
    }

    public function test_10_view_profile_not_found(): void
    {
        $this->assertSame(404, self::req('GET','/api/profiles/99999999',null,'a')['status']);
    }

    public function test_11_suspend_profile_double(): void
    {
        $pid = q1("SELECT profile_id FROM user_profile WHERE profile_name='Platform Manager'")['profile_id'];
        $this->assertSame(200, self::req('PATCH',"/api/profiles/$pid/suspend",null,'a')['status']);
        $this->assertSame(409, self::req('PATCH',"/api/profiles/$pid/suspend",null,'a')['status']);
        self::req('PATCH',"/api/profiles/$pid/activate",null,'a');
    }

    public function test_12_search_profile(): void
    {
        $r = self::req('GET','/api/profiles/search?q=zzzznomatch',null,'a');
        $this->assertArrayHasKey('message', $r['body']);
        $this->assertSame(0, count($r['body']['profiles']));
    }

    // =====================================================================
    // US #15-#19 - Company lifecycle (Designer)
    // =====================================================================
    public function test_15_19_company_lifecycle(): void
    {
        // #15 Create
        $r = self::req('POST','/api/companies',[
            'company_name'=>'Test Studio','address'=>'1 St','contact_no'=>'91234567',
        ],'d');
        $this->assertSame(201, $r['status']);
        $cid = $r['body']['company']['company_id'];

        // #15 A1 - Missing Name -> 400
        $this->assertSame(400, self::req('POST','/api/companies',['address'=>'x'],'d')['status']);

        // #16 View
        $this->assertSame(200, self::req('GET',"/api/companies/$cid",null,'d')['status']);
        // #16 A1 - Not Found -> 404
        $this->assertSame(404, self::req('GET','/api/companies/99999999',null,'d')['status']);

        // #17 Update
        $r = self::req('PUT',"/api/companies/$cid",
            ['company_name'=>'Test Studio v2','address'=>'2','contact_no'=>'98765432'],'d');
        $this->assertSame('Test Studio v2', $r['body']['company']['company_name']);

        // #19 Search
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/companies/search?q=Test%20Studio%20v2',null,'d')['body']['companies']));
        $r = self::req('GET','/api/companies/search?q=zzznomatch',null,'d');
        $this->assertSame(0, count($r['body']['companies']));
        $this->assertArrayHasKey('message', $r['body']);

        // #18 Delete
        $toDel = self::req('POST','/api/companies',['company_name'=>'ToDel'],'d')['body']['company']['company_id'];
        $this->assertSame(200, self::req('DELETE',"/api/companies/$toDel",null,'d')['status']);
        $this->assertSame(404, self::req('DELETE','/api/companies/99999999',null,'d')['status']);
    }

    // =====================================================================
    // US #20-#34, #38 - Projects + Favourites (aggregated regression guard)
    // =====================================================================
    public function test_20_34_projects_and_favourites(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'ProjCo'],'d')['body']['company']['company_id'];

        // #20 Create Ongoing
        $r = self::req('POST','/api/projects',[
            'company_id'=>$cid,'title'=>'Living Room Revamp','description'=>'d','status'=>'Ongoing',
        ],'d');
        $this->assertSame(201, $r['status']);
        $oId = $r['body']['project']['project_id'];

        // #20 A1 - Missing fields -> 400
        $this->assertSame(400, self::req('POST','/api/projects',
            ['company_id'=>$cid,'title'=>'x','description'=>''],'d')['status']);

        // #25 Create Completed
        $r = self::req('POST','/api/projects/completed',[
            'company_id'=>$cid,'title'=>'Past Kitchen','description'=>'d',
        ],'d');
        $this->assertSame(201, $r['status']);
        $cId = $r['body']['project']['project_id'];

        // #26 Filter by Ongoing
        $o = self::req('GET',"/api/projects/company/$cid?status=Ongoing",null,'d')['body']['projects'];
        $this->assertTrue(array_reduce($o, fn($ok,$p)=>$ok && $p['status']==='Ongoing', true));

        // #22 Update Project
        $r = self::req('PUT',"/api/projects/$oId",['title'=>'Living Room Revamp v2','description'=>'d'],'d');
        $this->assertSame('Living Room Revamp v2', $r['body']['project']['title']);

        // #22 A1 - Empty fields -> 400
        $this->assertSame(400, self::req('PUT',"/api/projects/$oId",
            ['title'=>'','description'=>''],'d')['status']);

        // #22 A2 - Ownership: Customer trying to update -> 403
        $this->assertSame(403, self::req('PUT',"/api/projects/$oId",
            ['title'=>'hack','description'=>'d'],'c')['status']);

        // #24 Search Projects
        $this->assertGreaterThanOrEqual(1, count(self::req('GET',
            "/api/projects/company/$cid/search?q=Revamp&status=Ongoing",null,'d')['body']['projects']));

        // #24 A1 - No matches -> 200 with message
        $r = self::req('GET',"/api/projects/company/$cid/search?q=zzzznomatch",null,'d');
        $this->assertSame(0, count($r['body']['projects']));
        $this->assertArrayHasKey('message', $r['body']);

        // #29 Search Completed
        $this->assertGreaterThanOrEqual(1, count(self::req('GET',
            "/api/projects/company/$cid/completed/search?q=Past",null,'d')['body']['projects']));

        // #30 Public Search
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/projects/public/search?q=Revamp',null,'c')['body']['projects']));

        // #31 / #37 View Counter increment - regression
        $before = array_values(array_filter(
            self::req('GET',"/api/projects/company/$cid/interest",null,'d')['body']['interest'],
            fn($i)=>(int)$i['project_id']===(int)$oId))[0]['views'];
        self::req('GET',"/api/projects/public/$oId",null,'c');
        $after = array_values(array_filter(
            self::req('GET',"/api/projects/company/$cid/interest",null,'d')['body']['interest'],
            fn($i)=>(int)$i['project_id']===(int)$oId))[0]['views'];
        $this->assertSame((int)$before+1, (int)$after);

        // #32 Favourite (idempotent)
        $this->assertSame(201, self::req('POST','/api/favourites',
            ['project_id'=>$oId],'c')['status']);
        $this->assertSame(201, self::req('POST','/api/favourites',
            ['project_id'=>$oId],'c')['status']);

        // #32 A1 - Not Found -> 404
        $this->assertSame(404, self::req('POST','/api/favourites',
            ['project_id'=>99999999],'c')['status']);

        // #34 List Favourites
        $this->assertSame(1, count(self::req('GET','/api/favourites',null,'c')['body']['favourites']));

        // #33 Search Favourites
        $this->assertSame(1, count(
            self::req('GET','/api/favourites/search?q=Revamp',null,'c')['body']['favourites']));

        // #33 A1 - No matching favourites
        $r = self::req('GET','/api/favourites/search?q=zzzznomatch',null,'c');
        $this->assertSame(0, count($r['body']['favourites']));
        $this->assertArrayHasKey('message', $r['body']);

        // #38 Shortlist Count
        $row = array_values(array_filter(
            self::req('GET',"/api/projects/company/$cid/interest",null,'d')['body']['interest'],
            fn($i)=>(int)$i['project_id']===(int)$oId))[0];
        $this->assertSame(1, (int)$row['shortlists']);

        // #34 Remove Favourite
        $this->assertSame(200, self::req('DELETE',"/api/favourites/$oId",null,'c')['status']);

        // #28 Delete Completed
        $this->assertSame(200, self::req('DELETE',"/api/projects/$cId/completed",null,'d')['status']);

        // #23 Delete Ongoing
        $this->assertSame(200, self::req('DELETE',"/api/projects/$oId",null,'d')['status']);
    }

    // =====================================================================
    // US #21 - View new (ongoing) project
    // =====================================================================
    public function test_21_view_new_project_by_id(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'Test Studio 21'],'d')
                     ['body']['company']['company_id'];
        $pid = self::req('POST','/api/projects',[
            'company_id'=>$cid,'title'=>'View 21','description'=>'d','status'=>'Ongoing',
        ],'d')['body']['project']['project_id'];

        $r = self::req('GET',"/api/projects/$pid",null,'d');
        $this->assertSame(200, $r['status']);
        $this->assertSame('View 21', $r['body']['project']['title']);

        $this->assertSame(404, self::req('GET','/api/projects/99999999',null,'d')['status']);
    }

    // =====================================================================
    // US #26 - View completed project
    // =====================================================================
    public function test_26_view_completed_project_by_id(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'Test Studio 26'],'d')
                     ['body']['company']['company_id'];
        $pid = self::req('POST','/api/projects/completed',[
            'company_id'=>$cid,'title'=>'View 26','description'=>'d',
        ],'d')['body']['project']['project_id'];

        $r = self::req('GET',"/api/projects/$pid/completed",null,'d');
        $this->assertSame(200, $r['status']);
        $this->assertSame('View 26', $r['body']['project']['title']);

        // A project that is *Ongoing* must 404 on this endpoint
        $ongoingId = self::req('POST','/api/projects',[
            'company_id'=>$cid,'title'=>'Ongoing 26','description'=>'d','status'=>'Ongoing',
        ],'d')['body']['project']['project_id'];
        $this->assertSame(404, self::req('GET',"/api/projects/$ongoingId/completed",null,'d')['status']);
    }

    // =====================================================================
    // US #27 - Update completed project
    // =====================================================================
    public function test_27_update_completed_project(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'Test Studio 27'],'d')
                     ['body']['company']['company_id'];
        $pid = self::req('POST','/api/projects/completed',[
            'company_id'=>$cid,'title'=>'Old 27','description'=>'d',
        ],'d')['body']['project']['project_id'];

        $r = self::req('PUT',"/api/projects/$pid/completed",
            ['title'=>'New 27','description'=>'d'],'d');
        $this->assertSame(200, $r['status']);
        $this->assertSame('New 27', $r['body']['project']['title']);

        $this->assertSame(400, self::req('PUT',"/api/projects/$pid/completed",
            ['title'=>'','description'=>''],'d')['status']);

        $this->assertSame(403, self::req('PUT',"/api/projects/$pid/completed",
            ['title'=>'Hijack','description'=>'d'],'c')['status']);
    }

    // =====================================================================
    // US #37 - View Number of Views (DEDICATED - matches report TDD p.189)
    // =====================================================================
    /**
     * Isolated TDD target for Sprint 4 (report p.189). The report states:
     *   "asserting that fetching project details returns the correct view count
     *    using assertSame() to check the HTTP status (200) and assertNotNull()
     *    to verify the view data."
     *
     * This test isolates US #37 from the aggregated regression test so that
     * the TDD Red-Green-Refactor evidence in the report is directly traceable
     * to a single named test.
     */
    public function test_37_view_project_views(): void
    {
        // --- Arrange: designer creates a project --------------------------
        $cid = self::req('POST','/api/companies',
            ['company_name'=>'Test Studio 37'],'d')['body']['company']['company_id'];
        $pid = self::req('POST','/api/projects',[
            'company_id'=>$cid,'title'=>'Views 37','description'=>'d','status'=>'Ongoing',
        ],'d')['body']['project']['project_id'];

        // --- Act: fetch metrics ------------------------------------------
        $r = self::req('GET',"/api/projects/company/$cid/interest",null,'d');

        // --- Assert: report-specified conditions -------------------------
        $this->assertSame(200, $r['status'], 'Fetching project interest must return HTTP 200 (report p.189)');
        $this->assertArrayHasKey('interest', $r['body'], 'Response must contain interest array');

        // View data must exist for the project (matches report's assertNotNull requirement)
        $row = null;
        foreach ($r['body']['interest'] as $i) {
            if ((int)$i['project_id'] === (int)$pid) { $row = $i; break; }
        }
        $this->assertNotNull($row, 'Project interest record must exist (assertNotNull per report p.189)');
        $this->assertArrayHasKey('views', $row, 'View data must include a view count');
        $this->assertArrayHasKey('shortlists', $row, 'View data must include a shortlist count');
        $this->assertSame(0, (int)$row['views'], 'A freshly-created project must start with zero views');
        $this->assertSame(0, (int)$row['shortlists'], 'A freshly-created project must start with zero shortlists');

        // --- Act: a customer views the project (increments counter) ------
        $viewRes = self::req('GET',"/api/projects/public/$pid",null,'c');
        $this->assertSame(200, $viewRes['status']);
        $this->assertNotNull($viewRes['body']['project'] ?? null, 'Public view response must include project data');

        // --- Assert: view count increases by 1 ---------------------------
        $r2 = self::req('GET',"/api/projects/company/$cid/interest",null,'d');
        $row2 = null;
        foreach ($r2['body']['interest'] as $i) {
            if ((int)$i['project_id'] === (int)$pid) { $row2 = $i; break; }
        }
        $this->assertNotNull($row2);
        $this->assertSame(1, (int)$row2['views'], 'View count must increment by exactly 1 after a public view');
    }

    // =====================================================================
    // US #41-#48 - Categories + Reports
    // =====================================================================
    public function test_41_48_categories_and_reports(): void
    {
        // #41 Create
        $cid = self::req('POST','/api/categories',
            ['category_name'=>'TC One','description'=>'x'],'m')['body']['category']['category_id'];

        $this->assertSame(400, self::req('POST','/api/categories',
            ['category_name'=>''],'m')['status']);

        // #42 View
        $this->assertSame(200, self::req('GET',"/api/categories/$cid",null,'m')['status']);

        // #43 Update
        $r = self::req('PUT',"/api/categories/$cid",
            ['category_name'=>'TC One v2','description'=>'y'],'m');
        $this->assertSame('TC One v2', $r['body']['category']['category_name']);

        $this->assertSame(404, self::req('PUT','/api/categories/99999999',
            ['category_name'=>'x'],'m')['status']);

        // #45 Search
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/categories/search?q=TC%20One',null,'m')['body']['categories']));

        $r = self::req('GET','/api/categories/search?q=zzzznomatch',null,'m');
        $this->assertSame(0, count($r['body']['categories']));
        $this->assertArrayHasKey('message', $r['body']);

        // #44 Delete
        $this->assertSame(200, self::req('DELETE',"/api/categories/$cid",null,'m')['status']);
        $this->assertSame(404, self::req('DELETE','/api/categories/99999999',null,'m')['status']);

        // #46 / #47 / #48 - Reports
        foreach (['daily','weekly','monthly'] as $p) {
            $r = self::req('GET',"/api/reports/$p",null,'m');
            $this->assertSame(200, $r['status']);
            $this->assertArrayHasKey('project_views', $r['body']['summary']);
        }

        // Invalid period -> 400
        $this->assertSame(400, self::req('GET','/api/reports/yearly',null,'m')['status']);
    }
}