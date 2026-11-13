// demo.js - Comprehensive 48-User Story Smoke Test
// Run with: node demo.js

const BASE_URL = 'http://localhost:8000/api';
let cookie = ''; 

async function api(method, path, body = null) {
    const headers = { 'Content-Type': 'application/json' };
    if (cookie) headers['Cookie'] = cookie;

    const options = { method, headers };
    if (body) options.body = JSON.stringify(body);

    const res = await fetch(`${BASE_URL}${path}`, options);
    
    const setCookie = res.headers.get('set-cookie');
    if (setCookie) {
        cookie = setCookie.split(';')[0]; 
    }

    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch { data = text; }

    return { status: res.status, data };
}

function check(story, name, passed, debugData = null) {
    const icon = passed ? '✅' : '❌';
    console.log(`[US #${story}] ${icon} ${name}`);
    if (!passed && debugData) {
        console.log(`      ↳ API returned:`, JSON.stringify(debugData).substring(0, 150));
    }
}

async function runTests() {
    console.log("🚀 Starting Comprehensive 48-Story Demo Tests...\n");
    const uniqueId = Date.now();

    // ==========================================
    // 1. USER ADMIN (US #1 - #12)
    // ==========================================
    console.log("--- USER ADMIN ---");
    let res = await api('POST', '/auth/login', { email: 'admin@example.test', password: 'Password123!' });
    check(1, 'Login as Admin', res.status === 200);

    const testEmail = `test-${uniqueId}@example.test`;
    res = await api('POST', '/users', { username: `user-${uniqueId}`, email: testEmail, password: 'Password123!', profile_id: 2 });
    check(3, 'Create User Account', res.status === 201);
    const newUserId = res.data?.id || res.data?.user?.account_id || 5;

    res = await api('GET', `/users/${newUserId}`);
    check(5, 'View User Account', res.status === 200);

    res = await api('PUT', `/users/${newUserId}`, { username: `user-updated-${uniqueId}`, email: testEmail, profile_id: 2 });
    check(4, 'Update User Account', res.status === 200);

    res = await api('PATCH', `/users/${newUserId}/suspend`);
    check(6, 'Suspend User Account', res.status === 200);

    res = await api('GET', `/users/search?q=${testEmail}`);
    check(7, 'Search User Account', res.status === 200 && JSON.stringify(res.data).includes(testEmail), res.data);

    res = await api('POST', '/profiles', { profile_name: `Profile ${uniqueId}`, description: 'Test', permissions: ['VIEW'] });
    check(8, 'Create User Profile', res.status === 201);
    const newProfileId = res.data?.id || res.data?.profile?.profile_id || 5;

    res = await api('GET', `/profiles/${newProfileId}`);
    check(10, 'View User Profile', res.status === 200);

    res = await api('PUT', `/profiles/${newProfileId}`, { profile_name: `Profile Updated ${uniqueId}`, description: 'Test', permissions: ['VIEW'] });
    check(9, 'Update User Profile', res.status === 200);

    res = await api('PATCH', `/profiles/${newProfileId}/suspend`);
    check(11, 'Suspend User Profile', res.status === 200);

    res = await api('GET', `/profiles/search?q=Profile`);
    check(12, 'Search User Profile', res.status === 200);

    res = await api('POST', '/auth/logout');
    check(2, 'Logout Admin', res.status === 200);
    console.log("");


    // ==========================================
    // 2. INTERIOR DESIGNER (US #13 - #29, #37 - #38)
    // ==========================================
    console.log("--- INTERIOR DESIGNER ---");
    res = await api('POST', '/auth/login', { email: 'designer@example.test', password: 'Password123!' });
    check(13, 'Login as Designer', res.status === 200);

    res = await api('POST', '/companies', { company_name: `Test Firm ${uniqueId}`, address: '123 Test St', contact_no: '91234567' });
    check(15, 'Create Company', res.status === 201);
    const newCompanyId = res.data?.id || res.data?.company?.company_id || 3;

    res = await api('GET', `/companies/${newCompanyId}`);
    check(16, 'View Company', res.status === 200);

    res = await api('PUT', `/companies/${newCompanyId}`, { company_name: `Test Firm ${uniqueId}`, address: '456 New St', contact_no: '91234567' });
    check(17, 'Update Company', res.status === 200);

    res = await api('GET', `/companies/search?q=Test Firm`);
    check(19, 'Search Company', res.status === 200);

    res = await api('POST', '/projects', { company_id: newCompanyId, title: `Project ${uniqueId}`, description: 'Test project', status: 'Ongoing' });
    check(20, 'Create Ongoing Project', res.status === 201);
    const newProjectId = res.data?.id || res.data?.project?.project_id || 5;

    res = await api('GET', `/projects/${newProjectId}`);
    check(21, 'View Ongoing Project', res.status === 200);

    res = await api('PUT', `/projects/${newProjectId}`, { company_id: newCompanyId, title: `Project Updated ${uniqueId}`, description: 'Test project', status: 'Ongoing' });
    check(22, 'Update Project', res.status === 200);

    res = await api('GET', `/projects/search?q=Project&status=Ongoing`);
    check(24, 'Search Ongoing Projects', res.status === 200, res.data);

    res = await api('POST', '/projects/completed', { company_id: newCompanyId, title: `Completed ${uniqueId}`, description: 'Done', status: 'Completed' });
    check(25, 'Create Completed Project', res.status === 201);
    const newCompletedId = res.data?.id || res.data?.project?.project_id || 6;

    res = await api('GET', `/projects/${newCompletedId}/completed`);
    check(26, 'View Completed Project', res.status === 200);

    res = await api('PUT', `/projects/${newCompletedId}/completed`, { company_id: newCompanyId, title: `Completed Updated ${uniqueId}`, description: 'Done', status: 'Completed' });
    check(27, 'Update Completed Project', res.status === 200);

    res = await api('GET', `/projects/company/${newCompanyId}/completed/search?q=Completed`);
    check(29, 'Search Completed Project', res.status === 200);

    res = await api('GET', `/projects/company/${newCompanyId}/interest`);
    check(37, 'View Project Analytics (Views)', res.status === 200);
    check(38, 'View Project Analytics (Saves)', res.status === 200);

    res = await api('DELETE', `/projects/${newCompletedId}/completed`);
    check(28, 'Delete Completed Project', res.status === 200);

    res = await api('DELETE', `/projects/${newProjectId}`);
    check(23, 'Delete Ongoing Project', res.status === 200);

    res = await api('DELETE', `/companies/${newCompanyId}`);
    check(18, 'Delete Company', res.status === 200);

    res = await api('POST', '/auth/logout');
    check(14, 'Logout Designer', res.status === 200);
    console.log("");


    // ==========================================
    // 3. CUSTOMER (US #30 - #36)
    // ==========================================
    console.log("--- CUSTOMER ---");
    res = await api('POST', '/auth/login', { email: 'customer@example.test', password: 'Password123!' });
    check(35, 'Login as Customer', res.status === 200);

    res = await api('GET', '/projects/public/search?q=Project');
    check(30, 'Search Public Projects', res.status === 200);
    
    let publicProjectId = 1; 
    if (Array.isArray(res.data) && res.data.length > 0) publicProjectId = res.data[0].project_id || res.data[0].id;
    else if (res.data.projects && res.data.projects.length > 0) publicProjectId = res.data.projects[0].project_id || res.data.projects[0].id;

    res = await api('GET', `/projects/public/${publicProjectId}`);
    check(31, 'View Public Project (Increments Views)', res.status === 200);

    res = await api('POST', '/favourites', { project_id: publicProjectId });
    check(32, 'Save Project to Favourites', res.status === 201);

    res = await api('GET', '/favourites');
    check(34, 'View Favourites List', res.status === 200);

    res = await api('GET', '/favourites/search?q=Project');
    check(33, 'Search Favourites', res.status === 200);

    res = await api('DELETE', `/favourites/${publicProjectId}`);
    check(34, 'Remove from Favourites', res.status === 200);

    res = await api('POST', '/auth/logout');
    check(36, 'Logout Customer', res.status === 200);
    console.log("");


    // ==========================================
    // 4. PLATFORM MANAGER (US #39 - #48)
    // ==========================================
    console.log("--- PLATFORM MANAGER ---");
    res = await api('POST', '/auth/login', { email: 'manager@example.test', password: 'Password123!' });
    check(39, 'Login as Manager', res.status === 200);

    res = await api('POST', '/categories', { category_name: `Cat ${uniqueId}`, description: 'Test' });
    check(41, 'Create IDP Category', res.status === 201);
    const newCatId = res.data?.id || res.data?.category?.category_id || 4;

    res = await api('GET', `/categories/${newCatId}`);
    check(42, 'View IDP Category', res.status === 200);

    res = await api('PUT', `/categories/${newCatId}`, { category_name: `Cat ${uniqueId} v2`, description: 'Test' });
    check(43, 'Update IDP Category', res.status === 200);

    res = await api('GET', `/categories/search?q=Cat`);
    check(45, 'Search IDP Category', res.status === 200);

    res = await api('GET', '/reports/daily');
    check(46, 'Generate Daily Report', res.status === 200);

    res = await api('GET', '/reports/weekly');
    check(47, 'Generate Weekly Report', res.status === 200);

    res = await api('GET', '/reports/monthly');
    check(48, 'Generate Monthly Report', res.status === 200);

    res = await api('DELETE', `/categories/${newCatId}`);
    check(44, 'Delete IDP Category', res.status === 200);

    res = await api('POST', '/auth/logout');
    check(40, 'Logout Manager', res.status === 200);

    console.log("\n🏁 All 48 User Stories tested.");
}

runTests().catch(console.error);