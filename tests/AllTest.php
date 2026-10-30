<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class AllTest extends TestCase
{
    private const BASE = 'http://localhost:8000';
    private static array $cookies = [];

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

    public static function setUpBeforeClass(): void
    {
        $emails = ['idtest@example.test','custtest@example.test','admintest@example.test','pmtest@example.test',
                   'user+a@example.test','user+b@example.test','user+c@example.test','user+d@example.test'];
        foreach ($emails as $e) ex("DELETE FROM user_account WHERE email = $1", [$e]);
        ex("DELETE FROM company WHERE company_name IN ('Test Studio','Test Studio v2','ToDel','ProjCo')");
        ex("DELETE FROM category WHERE category_name LIKE 'TC%'");

        foreach ([
            ['User Admin',       '{user:manage,profile:manage}'],
            ['Interior Designer', '{company:manage,project:manage,interest:view}'],
            ['Customer',         '{project:view,favourite:manage}'],
            ['Platform Manager', '{category:manage,report:generate}'],
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

        foreach (['d'=>'idtest@example.test','c'=>'custtest@example.test','a'=>'admintest@example.test','m'=>'pmtest@example.test'] as $agent=>$email) {
            self::req('POST','/api/auth/login',['email'=>$email,'password'=>'Password123!'],$agent);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['idtest@example.test','custtest@example.test','admintest@example.test','pmtest@example.test',
                  'user+a@example.test','user+b@example.test','user+c@example.test','user+d@example.test'] as $e) {
            ex("DELETE FROM user_account WHERE email = $1", [$e]);
        }
        ex("DELETE FROM company WHERE company_name IN ('Test Studio','Test Studio v2','ToDel','ProjCo')");
        ex("DELETE FROM category WHERE category_name LIKE 'TC%'");
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
            ['email'=>'idtest@example.test','password'=>'wrong'])['status']);
    }
    public function test_01_13_35_39_login_and_me(): void
    {
        $ag = 'x1';
        self::req('POST','/api/auth/login',['email'=>'idtest@example.test','password'=>'Password123!'],$ag);
        $me = self::req('GET','/api/auth/me',null,$ag);
        $this->assertSame('idtest', $me['body']['user']['username']);
    }

    // =========================================================================
    // US #2, #14, #36, #40 – Logout
    // =========================================================================
    public function test_02_14_36_40_logout(): void
    {
        $ag = 'x2';
        self::req('POST','/api/auth/login',['email'=>'idtest@example.test','password'=>'Password123!'],$ag);
        self::req('POST','/api/auth/logout',null,$ag);
        $this->assertSame(401, self::req('GET','/api/auth/me',null,$ag)['status']);
    }

    // =========================================================================
    // US #3 – Create User Account (missing + duplicate)
    // =========================================================================
    public function test_03_create_user_missing_duplicate_search(): void
    {
        $custId = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];

        $r = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+a',
            'email'=>'user+a@example.test','password'=>'Password123!',
        ],'a');
        $this->assertSame(201, $r['status']);

        // #3 A2 – duplicate
        $r = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+b',
            'email'=>'user+a@example.test','password'=>'x',
        ],'a');
        $this->assertSame(409, $r['status']);

        // #3 A1 – missing
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
    // US #6 – Suspend User Account
    // =========================================================================
    public function test_06_suspend_user_double(): void
    {
        $custId = q1("SELECT profile_id FROM user_profile WHERE profile_name='Customer'")['profile_id'];
        $id = self::req('POST','/api/users',[
            'profile_id'=>$custId,'username'=>'user+c',
            'email'=>'user+c@example.test','password'=>'Password123!',
        ],'a')['body']['user']['account_id'];
        $this->assertSame(200, self::req('PATCH',"/api/users/$id/suspend",null,'a')['status']);
        // A1 – already suspended
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
    // US #15–#19 – Company lifecycle (with all error cases)
    // =========================================================================
    public function test_15_19_company_lifecycle(): void
    {
        // #15 create
        $r = self::req('POST','/api/companies',[
            'company_name'=>'Test Studio','address'=>'1 St','contact_no'=>'+65',
        ],'d');
        $this->assertSame(201, $r['status']);
        $cid = $r['body']['company']['company_id'];

        // #15 missing name
        $this->assertSame(400, self::req('POST','/api/companies',['address'=>'x'],'d')['status']);

        // #16 view
        $this->assertSame(200, self::req('GET',"/api/companies/$cid",null,'d')['status']);
        // #16 not found
        $this->assertSame(404, self::req('GET','/api/companies/99999999',null,'d')['status']);

        // #17 update
        $r = self::req('PUT',"/api/companies/$cid",
            ['company_name'=>'Test Studio v2','address'=>'2','contact_no'=>'3'],'d');
        $this->assertSame('Test Studio v2', $r['body']['company']['company_name']);

        // #19 search
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/companies/search?q=Test%20Studio%20v2',null,'d')['body']['companies']));
        // #19 A1 – no matches → message
        $r = self::req('GET','/api/companies/search?q=zzznomatch',null,'d');
        $this->assertSame(0, count($r['body']['companies']));
        $this->assertArrayHasKey('message', $r['body']);

        // #18 delete
        $toDel = self::req('POST','/api/companies',['company_name'=>'ToDel'],'d')['body']['company']['company_id'];
        $this->assertSame(200, self::req('DELETE',"/api/companies/$toDel",null,'d')['status']);
        // #18 A1 – not found
        $this->assertSame(404, self::req('DELETE','/api/companies/99999999',null,'d')['status']);
    }

    // =========================================================================
    // US #20–#34 – Projects + Favourites
    // =========================================================================
    public function test_20_34_projects_and_favourites(): void
    {
        $cid = self::req('POST','/api/companies',['company_name'=>'ProjCo'],'d')['body']['company']['company_id'];

        // #20 create ongoing
        $r = self::req('POST','/api/projects',[
            'company_id'=>$cid,'title'=>'Living Room Revamp','description'=>'d','status'=>'Ongoing',
        ],'d');
        $this->assertSame(201, $r['status']);
        $oId = $r['body']['project']['project_id'];

        // #20 A1 – missing fields
        $this->assertSame(400, self::req('POST','/api/projects',
            ['company_id'=>$cid,'title'=>'x','description'=>''],'d')['status']);

        // #25 create completed
        $r = self::req('POST','/api/projects/completed',[
            'company_id'=>$cid,'title'=>'Past Kitchen','description'=>'d',
        ],'d');
        $this->assertSame(201, $r['status']);
        $cId = $r['body']['project']['project_id'];

        // #26 filter
        $o = self::req('GET',"/api/projects/company/$cid?status=Ongoing",null,'d')['body']['projects'];
        $this->assertTrue(array_reduce($o, fn($ok,$p)=>$ok && $p['status']==='Ongoing', true));

        // #22 update
        $r = self::req('PUT',"/api/projects/$oId",['title'=>'Living Room Revamp v2','description'=>'d'],'d');
        $this->assertSame('Living Room Revamp v2', $r['body']['project']['title']);

        // #22 A1 – empty fields
        $this->assertSame(400, self::req('PUT',"/api/projects/$oId",
            ['title'=>'','description'=>''],'d')['status']);

        // #22 – non-owner
        $this->assertSame(403, self::req('PUT',"/api/projects/$oId",
            ['title'=>'hack','description'=>'d'],'c')['status']);

        // #24 search
        $this->assertGreaterThanOrEqual(1, count(self::req('GET',
            "/api/projects/company/$cid/search?q=Revamp&status=Ongoing",null,'d')['body']['projects']));

        // #24 A1 – no match → message
        $r = self::req('GET',"/api/projects/company/$cid/search?q=zzzznomatch",null,'d');
        $this->assertSame(0, count($r['body']['projects']));
        $this->assertArrayHasKey('message', $r['body']);

        // #29 search completed
        $this->assertGreaterThanOrEqual(1, count(self::req('GET',
            "/api/projects/company/$cid/completed/search?q=Past",null,'d')['body']['projects']));

        // #30 public search
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/projects/public/search?q=Revamp',null,'c')['body']['projects']));

        // #31 / #37 view counter
        $before = array_values(array_filter(
            self::req('GET',"/api/projects/company/$cid/interest",null,'d')['body']['interest'],
            fn($i)=>(int)$i['project_id']===(int)$oId))[0]['views'];
        self::req('GET',"/api/projects/public/$oId",null,'c');
        $after = array_values(array_filter(
            self::req('GET',"/api/projects/company/$cid/interest",null,'d')['body']['interest'],
            fn($i)=>(int)$i['project_id']===(int)$oId))[0]['views'];
        $this->assertSame((int)$before+1, (int)$after);

        // #32 favourite (idempotent)
        $this->assertSame(201, self::req('POST','/api/favourites',
            ['project_id'=>$oId],'c')['status']);
        $this->assertSame(201, self::req('POST','/api/favourites',
            ['project_id'=>$oId],'c')['status']);

        // #32 A1 – project not exist
        $this->assertSame(404, self::req('POST','/api/favourites',
            ['project_id'=>99999999],'c')['status']);

        // #34 list
        $this->assertSame(1, count(self::req('GET','/api/favourites',null,'c')['body']['favourites']));

        // #33 search
        $this->assertSame(1, count(
            self::req('GET','/api/favourites/search?q=Revamp',null,'c')['body']['favourites']));

        // #33 A1 – no matching favourites → message
        $r = self::req('GET','/api/favourites/search?q=zzzznomatch',null,'c');
        $this->assertSame(0, count($r['body']['favourites']));
        $this->assertArrayHasKey('message', $r['body']);

        // #38 shortlist count
        $row = array_values(array_filter(
            self::req('GET',"/api/projects/company/$cid/interest",null,'d')['body']['interest'],
            fn($i)=>(int)$i['project_id']===(int)$oId))[0];
        $this->assertSame(1, (int)$row['shortlists']);

        // #34 remove
        $this->assertSame(200, self::req('DELETE',"/api/favourites/$oId",null,'c')['status']);

        // #28 delete completed
        $this->assertSame(200, self::req('DELETE',"/api/projects/$cId/completed",null,'d')['status']);

        // #23 delete ongoing
        $this->assertSame(200, self::req('DELETE',"/api/projects/$oId",null,'d')['status']);
    }

    // =========================================================================
    // US #41–#48 – Categories + Reports
    // =========================================================================
    public function test_41_48_categories_and_reports(): void
    {
        // #41 create
        $cid = self::req('POST','/api/categories',
            ['category_name'=>'TC One','description'=>'x'],'m')['body']['category']['category_id'];

        // #41 missing name
        $this->assertSame(400, self::req('POST','/api/categories',
            ['category_name'=>''],'m')['status']);

        // #42 view
        $this->assertSame(200, self::req('GET',"/api/categories/$cid",null,'m')['status']);

        // #43 update
        $r = self::req('PUT',"/api/categories/$cid",
            ['category_name'=>'TC One v2','description'=>'y'],'m');
        $this->assertSame('TC One v2', $r['body']['category']['category_name']);

        // #43 A1 – update failed (not found)
        $this->assertSame(404, self::req('PUT','/api/categories/99999999',
            ['category_name'=>'x'],'m')['status']);

        // #45 search
        $this->assertGreaterThanOrEqual(1,
            count(self::req('GET','/api/categories/search?q=TC%20One',null,'m')['body']['categories']));

        // #45 A1 – no matches
        $r = self::req('GET','/api/categories/search?q=zzzznomatch',null,'m');
        $this->assertSame(0, count($r['body']['categories']));
        $this->assertArrayHasKey('message', $r['body']);

        // #44 delete
        $this->assertSame(200, self::req('DELETE',"/api/categories/$cid",null,'m')['status']);

        // #44 A1 – delete failed (not found)
        $this->assertSame(404, self::req('DELETE','/api/categories/99999999',null,'m')['status']);

        // #46 / #47 / #48 – reports
        foreach (['daily','weekly','monthly'] as $p) {
            $r = self::req('GET',"/api/reports/$p",null,'m');
            $this->assertSame(200, $r['status']);
            $this->assertArrayHasKey('project_views', $r['body']['summary']);
        }
        $this->assertSame(400, self::req('GET','/api/reports/yearly',null,'m')['status']);
    }
}