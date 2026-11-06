<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * =============================================================================
 * INTEGRATION TEST SUITE (AllTest.php)
 * =============================================================================
 * This test suite simulates real user workflows by making HTTP requests
 * to the live API.
 *
 * KEY CONCEPTS:
 * - Agents ($agent): We maintain different sets of cookies to simulate
 *   different users logged in simultaneously (e.g., 'a' = Admin, 'd' = Designer).
 * - Status Codes: Even with our new rich display_error() JSON payload, the
 *   HTTP status codes (200, 400, 401, 403, 404, 409) remain unchanged, so
 *   these assertions remain valid!
 *
 * CLEANUP ORDER (critical):
 *   project.company_id uses ON DELETE RESTRICT, so test projects MUST be
 *   deleted BEFORE the companies that own them. And companies must be
 *   deleted BEFORE the test users, because company.owner_id cascades on
 *   user delete — and if a company still has a project, the cascade
 *   fails with FK code 23503.
 * =============================================================================
 */

class AllTest extends TestCase
{
    private const BASE = 'http://localhost:8000';
    private static array $cookies = [];

    /** Email of the designer account used across all tests. Owns every test company. */
    private const DESIGNER_EMAIL = 'idtest@example.test';

    /**
     * Custom cURL wrapper.
     */
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

    /**
     * Delete every artifact the test suite can create, in FK-safe order:
     *   1. Projects (because project.company_id is ON DELETE RESTRICT)
     *   2. Companies (via user cascade — but only AFTER projects are gone)
     *   3. Users (deleting the designer cascades all their companies)
     *   4. Categories (via explicit pattern)
     *
     * Filtering by owner email is more robust than matching company names,
     * because it also catches any test that creates a new company in the
     * future without needing to update a hardcoded name list.
     */
    private static function cleanTestArtifacts(): void
    {
        // 1. Delete projects owned by the test designer's companies (FK RESTRICT blocks this last)
        ex(
            "DELETE FROM project WHERE company_id IN (
                SELECT c.company_id FROM company c
                JOIN user_account u ON u.account_id = c.owner_id
                WHERE u.email = $1
            )",
            [self::DESIGNER_EMAIL]
        );

        // 2. Delete test users (designer delete cascades all remaining companies;
        //    other test users have no companies, so they're safe)
        $emails = [
            'idtest@example.test', 'custtest@example.test',
            'admintest@example.test', 'pmtest@example.test',
            'user+a@example.test', 'user+b@example.test',
            'user+c@example.test', 'user+d@example.test',
        ];
        foreach ($emails as $e) ex("DELETE FROM user_account WHERE email = $1", [$e]);

        // 3. Defensive cleanup of any stray companies from prior failed runs
        //    (in case the owner_id cascade missed them)
        ex("DELETE FROM company WHERE company_name LIKE 'Test Studio%' OR company_name IN ('ToDel','ProjCo')");

        // 4. Clean test categories
        ex("DELETE FROM category WHERE category_name LIKE 'TC%'");
    }

    /**
     * Runs ONCE before all tests.
     */
    public static function setUpBeforeClass(): void
    {
        self::cleanTestArtifacts();

        // Ensure base profiles exist
        foreach ([
            ['User Admin',        '{user:manage,profile:manage}'],
            ['Interior Designer', '{company:manage,project:manage,interest:view}'],
            ['Customer',          '{project:view,favourite:manage}'],
            ['Platform Manager',  '{category:manage,report:generate}'],
        ] as [$n, $p]) {
            ex('INSERT INTO user_profile (profile_name, permissions) VALUES ($1,$2) ON CONFLICT (profile_name) DO NOTHING', [$n, $p]);
        }

        // Seed test users
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

        // Log in all agents and cache their cookies
        foreach (['d'=>self::DESIGNER_EMAIL,'c'=>'custtest@example.test','a'=>'admintest@example.test','m'=>'pmtest@example.test'] as $agent=>$email) {
            self::req('POST','/api/auth/login',['email'=>$email,'password'=>'Password123!'],$agent);
        }
    }

    /**
     * Runs ONCE after all tests.
     */
    public static function tearDownAfterClass(): void
    {
        self::cleanTestArtifacts();
    }

    // =========================================================================
    // US #1, #13, #35, #39 – Login
    // =========================================================================

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

    // =========================================================================
    // US #2, #14, #36, #40 – Logout
    // =========================================================================

    public function test_02_14_36_40_logout(): void
    {
        $ag = 'x2';
        self::req('POST','/api/auth/login',['email'=>self::DESIGNER_EMAIL,'password'=>'Password123!'],$ag);
        self::req('POST','/api/auth/logout',null,$ag);
        $this->assertSame(401, self::req('GET','/api/auth/me',null,$ag)['status']);
    }

    // =========================================================================
    // US #3 – Create User Account (missing + duplicate)
    // =========================================================================

    public function test_03_create_user_missing_duplicate_search(): void
    {
        $custId = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];

        // Main Flow — success (201)
        $r = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+a',
            'email'=>'user+a@example.test','password'=>'Password123!',
        ],'a');
        $this->assertSame(201, $r['status']);

        // A2 – Duplicate email (409)
        $r = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+b',
            'email'=>'user+a@example.test','password'=>'x',
        ],'a');
        $this->assertSame(409, $r['status']);

        // A1 – Missing field (400)
        $this->assertSame(400, self::req('POST','/api/users',['username'=>'x'],'a')['status']);
    }

    // =========================================================================
    // US #4 – Update User Account
    // =========================================================================

    public function test_04_update_user(): void
    {
        $custId = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];
        $id = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+d',
            'email'=>'user+d@example.test','password'=>'Password123!',
        ],'a')['body']['user']['account_id'];

        $this->assertSame('user+d-up', self::req('PUT',"/api/users/$id",['username'=>'user+d-up'],'a')['body']['user']['username']);

        $this->assertSame(400, self::req('PUT',"/api/users/$id",['email'=>'not-an-email'],'a')['status']);
    }

    // =========================================================================
    // US #5 – View User Account (not found)
    // =========================================================================

    public function test_05_view_user_not_found(): void
    {
        $this->assertSame(404, self::req('GET','/api/users/99999999',null,'a')['status']);
    }

    // =========================================================================
    // US #6 – Suspend User Account (double)
    // =========================================================================

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

    // =========================================================================
    // US #7 – Search User Account
    // =========================================================================

    public function test_07_search_user(): void
    {
        $r = self::req('GET','/api/users/search?q=user%2Ba',null,'a');
        $this->assertGreaterThanOrEqual(1, count($r['body']['users']));
    }

    // =========================================================================
    // US #8 – Create Profile (empty + duplicate)
    // =========================================================================

    public function test_08_create_profile_empty_and_duplicate(): void
    {
        $this->assertSame(400, self::req('POST','/api/profiles',
            ['profile_name'=>''],'a')['status']);
    }

    // =========================================================================
    // US #9 – Update Profile
    // =========================================================================

    public function test_09_update_profile_empty_name(): void
    {
        $pid = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];
        $this->assertSame(400, self::req('PUT',"/api/profiles/$pid",
            ['profile_name'=>''],'a')['status']);
    }

    // =========================================================================
    // US #10 – View Profile (not found)
    // =========================================================================

    public function test_10_view_profile_not_found(): void
    {
        $this->assertSame(404, self::req('GET','/api/profiles/99999999',null,'a')['status']);
    }

    // =========================================================================
    // US #11 – Suspend Profile (double)
    // =========================================================================

    public function test_11_suspend_profile_double(): void
    {
        $pid = q1("SELECT profile_id FROM user_profile WHERE profile_name='Platform Manager'")['profile_id'];
        $this->assertSame(200, self::req('PATCH',"/api/profiles/$pid/suspend",null,'a')['status']);
        $this->assertSame(409, self::req('PATCH',"/api/profiles/$pid/suspend",null,'a')['status']);
        self::req('PATCH',"/api/profiles/$pid/activate",null,'a');
    }

    // =========================================================================
    // US #12 – Search Profile
    // =========================================================================

    public function test_12_search_profile(): void
    {
        $r = self::req('GET','/api/profiles/search?q=zzzznomatch',null,'a');
        $this->assertArrayHasKey('message', $r['body']);
        $this->assertSame(0, count($r['body']['profiles']));
    }

    // =========================================================================
    // US #15–#19 – Company lifecycle
    // =========================================================================

    public function test_15_19_company_lifecycle(): void
    {
        // #15 Create (with 8-digit contact number)
        $r = self::req('POST','/api/companies',[
            'company_name'=>'Test Studio','address'=>'1 St','contact_no'=>'91234567',
        ],'d');
        $this->assertSame(201, $r['status']);
        $cid = $r['body']['company']['company_id'];

        // #15 A1 – Missing Name -> 400
        $this->assertSame(400, self::req('POST','/api/companies',['address'=>'x'],'d')['status']);

        // #16 View
        $this->assertSame(200, self::req('GET',"/api/companies/$cid",null,'d')['status']);
        // #16 A1 – Not Found -> 404
        $this->assertSame(404, self::req('GET','/api/companies/99999999',null,'d')['status']);

        // #17 Update
        $r = self::req('PUT',"/api/companies/$cid",
            ['company_name'=>'Test Studio v2','address'=>'2','contact_no'=>'98765432'],'d');
        $this->assertSame('Test Studio v2', $r['body']['company']['company_name']);

        // #19 Search
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/companies/search?q=Test%20Studio%20v2',null,'d')['body']['companies']));
        // #19 A1 – No matches -> 200 with message
        $r = self::req('GET','/api/companies/search?q=zzznomatch',null,'d');
        $this->assertSame(0, count($r['body']['companies']));
        $this->assertArrayHasKey('message', $r['body']);

        // #18 Delete
        $toDel = self::req('POST','/api/companies',['company_name'=>'ToDel'],'d')['body']['company']['company_id'];
        $this->assertSame(200, self::req('DELETE',"/api/companies/$toDel",null,'d')['status']);
        // #18 A1 – Not Found -> 404
        $this->assertSame(404, self::req('DELETE','/api/companies/99999999',null,'d')['status']);
    }

    // =========================================================================
    // US #20–#34 – Projects + Favourites
    // =========================================================================

    public function test_20_34_projects_and_favourites(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'ProjCo'],'d')['body']['company']['company_id'];

        // #20 Create Ongoing
        $r = self::req('POST','/api/projects',[
            'company_id'=>$cid,'title'=>'Living Room Revamp','description'=>'d','status'=>'Ongoing',
        ],'d');
        $this->assertSame(201, $r['status']);
        $oId = $r['body']['project']['project_id'];

        // #20 A1 – Missing fields -> 400
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

        // #22 A1 – Empty fields -> 400
        $this->assertSame(400, self::req('PUT',"/api/projects/$oId",
            ['title'=>'','description'=>''],'d')['status']);

        // #22 A2 – Unauthorized (Customer trying to update Designer's project) -> 403
        $this->assertSame(403, self::req('PUT',"/api/projects/$oId",
            ['title'=>'hack','description'=>'d'],'c')['status']);

        // #24 Search Projects
        $this->assertGreaterThanOrEqual(1, count(self::req('GET',
            "/api/projects/company/$cid/search?q=Revamp&status=Ongoing",null,'d')['body']['projects']));

        // #24 A1 – No matches -> 200 with message
        $r = self::req('GET',"/api/projects/company/$cid/search?q=zzzznomatch",null,'d');
        $this->assertSame(0, count($r['body']['projects']));
        $this->assertArrayHasKey('message', $r['body']);

        // #29 Search Completed Projects
        $this->assertGreaterThanOrEqual(1, count(self::req('GET',
            "/api/projects/company/$cid/completed/search?q=Past",null,'d')['body']['projects']));

        // #30 Public Search
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/projects/public/search?q=Revamp',null,'c')['body']['projects']));

        // #31 / #37 View Counter Test
        $before = array_values(array_filter(
            self::req('GET',"/api/projects/company/$cid/interest",null,'d')['body']['interest'],
            fn($i)=>(int)$i['project_id']===(int)$oId))[0]['views'];

        self::req('GET',"/api/projects/public/$oId",null,'c');

        $after = array_values(array_filter(
            self::req('GET',"/api/projects/company/$cid/interest",null,'d')['body']['interest'],
            fn($i)=>(int)$i['project_id']===(int)$oId))[0]['views'];

        $this->assertSame((int)$before+1, (int)$after);

        // #32 Favourite (idempotent — saving twice should not crash)
        $this->assertSame(201, self::req('POST','/api/favourites',
            ['project_id'=>$oId],'c')['status']);
        $this->assertSame(201, self::req('POST','/api/favourites',
            ['project_id'=>$oId],'c')['status']);

        // #32 A1 – Project Not Found -> 404
        $this->assertSame(404, self::req('POST','/api/favourites',
            ['project_id'=>99999999],'c')['status']);

        // #34 List Favourites
        $this->assertSame(1, count(self::req('GET','/api/favourites',null,'c')['body']['favourites']));

        // #33 Search Favourites
        $this->assertSame(1, count(
            self::req('GET','/api/favourites/search?q=Revamp',null,'c')['body']['favourites']));

        // #33 A1 – No matching favourites -> 200 with message
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

        // #28 Delete Completed Project
        $this->assertSame(200, self::req('DELETE',"/api/projects/$cId/completed",null,'d')['status']);

        // #23 Delete Ongoing Project
        $this->assertSame(200, self::req('DELETE',"/api/projects/$oId",null,'d')['status']);
    }

    // =========================================================================
    // US #21 – View New (Ongoing) Project — single resource
    // =========================================================================

    public function test_21_view_new_project_by_id(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'Test Studio 21'],'d')
                     ['body']['company']['company_id'];
        $pid = self::req('POST','/api/projects',[
            'company_id'=>$cid,'title'=>'View 21','description'=>'d','status'=>'Ongoing',
        ],'d')['body']['project']['project_id'];

        // Success — 200 with the project body
        $r = self::req('GET',"/api/projects/$pid",null,'d');
        $this->assertSame(200, $r['status']);
        $this->assertSame('View 21', $r['body']['project']['title']);

        // A1 — Not Found -> 404
        $this->assertSame(404, self::req('GET','/api/projects/99999999',null,'d')['status']);
    }

    // =========================================================================
    // US #26 – View Completed Project — single resource
    // =========================================================================

    public function test_26_view_completed_project_by_id(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'Test Studio 26'],'d')
                     ['body']['company']['company_id'];
        $pid = self::req('POST','/api/projects/completed',[
            'company_id'=>$cid,'title'=>'View 26','description'=>'d',
        ],'d')['body']['project']['project_id'];

        // Success — 200 via the dedicated completed endpoint
        $r = self::req('GET',"/api/projects/$pid/completed",null,'d');
        $this->assertSame(200, $r['status']);
        $this->assertSame('View 26', $r['body']['project']['title']);

        // A1 — A project not actually Completed should 404 via this endpoint
        $ongoingId = self::req('POST','/api/projects',[
            'company_id'=>$cid,'title'=>'Ongoing 26','description'=>'d','status'=>'Ongoing',
        ],'d')['body']['project']['project_id'];
        $this->assertSame(404, self::req('GET',"/api/projects/$ongoingId/completed",null,'d')['status']);
    }

    // =========================================================================
    // US #27 – Update Completed Project
    // =========================================================================

    public function test_27_update_completed_project(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'Test Studio 27'],'d')
                     ['body']['company']['company_id'];
        $pid = self::req('POST','/api/projects/completed',[
            'company_id'=>$cid,'title'=>'Old 27','description'=>'d',
        ],'d')['body']['project']['project_id'];

        // Success — 200 with updated title
        $r = self::req('PUT',"/api/projects/$pid/completed",
            ['title'=>'New 27','description'=>'d'],'d');
        $this->assertSame(200, $r['status']);
        $this->assertSame('New 27', $r['body']['project']['title']);

        // A1 — Missing fields -> 400
        $this->assertSame(400, self::req('PUT',"/api/projects/$pid/completed",
            ['title'=>'','description'=>''],'d')['status']);

        // A2 — Ownership: a different user cannot edit it -> 403
        $this->assertSame(403, self::req('PUT',"/api/projects/$pid/completed",
            ['title'=>'Hijack','description'=>'d'],'c')['status']);
    }

    // =========================================================================
    // US #41–#48 – Categories + Reports
    // =========================================================================

    public function test_41_48_categories_and_reports(): void
    {
        // #41 Create Category
        $cid = self::req('POST','/api/categories',
            ['category_name'=>'TC One','description'=>'x'],'m')['body']['category']['category_id'];

        // #41 A1 – Missing name -> 400
        $this->assertSame(400, self::req('POST','/api/categories',
            ['category_name'=>''],'m')['status']);

        // #42 View Category
        $this->assertSame(200, self::req('GET',"/api/categories/$cid",null,'m')['status']);

        // #43 Update Category
        $r = self::req('PUT',"/api/categories/$cid",
            ['category_name'=>'TC One v2','description'=>'y'],'m');
        $this->assertSame('TC One v2', $r['body']['category']['category_name']);

        // #43 A1 – Update failed (not found) -> 404
        $this->assertSame(404, self::req('PUT','/api/categories/99999999',
            ['category_name'=>'x'],'m')['status']);

        // #45 Search Categories
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/categories/search?q=TC%20One',null,'m')['body']['categories']));

        // #45 A1 – No matches -> 200 with message
        $r = self::req('GET','/api/categories/search?q=zzzznomatch',null,'m');
        $this->assertSame(0, count($r['body']['categories']));
        $this->assertArrayHasKey('message', $r['body']);

        // #44 Delete Category
        $this->assertSame(200, self::req('DELETE',"/api/categories/$cid",null,'m')['status']);

        // #44 A1 – Delete failed (not found) -> 404
        $this->assertSame(404, self::req('DELETE','/api/categories/99999999',null,'m')['status']);

        // #46 / #47 / #48 – Reports
        foreach (['daily','weekly','monthly'] as $p) {
            $r = self::req('GET',"/api/reports/$p",null,'m');
            $this->assertSame(200, $r['status']);
            $this->assertArrayHasKey('project_views', $r['body']['summary']);
        }

        // Invalid report type (Yearly) -> 400
        $this->assertSame(400, self::req('GET','/api/reports/yearly',null,'m')['status']);
    }
}