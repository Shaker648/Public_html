<?php
/*
 * permissions.php — central permission engine.
 *
 * HOW IT WORKS
 *  - Every page / feature in the system has a permission KEY (see perm_catalog()).
 *  - The BUILT-IN DEFAULTS below reproduce exactly how the site behaved before
 *    this system existed (admin / manager / sales hard-coded checks).
 *  - The admin can override any default per ROLE, and additionally per USER,
 *    from permissions_admin.php (reached via the Users page).
 *  - Overrides are stored in two tables (auto-created on first use):
 *        role_permissions (role, perm_key, allowed)
 *        user_permissions (user_id, perm_key, allowed)
 *  - Resolution order:  user override → role override → built-in default.
 *  - "Reset to default" simply deletes the override rows.
 *
 * SAFETY LOCKS (cannot be changed from the UI, enforced here):
 *  - page.dashboard is ALWAYS allowed (denying it would create redirect loops).
 *  - The admin role can NEVER lose page.users — that page is the door to the
 *    permission manager, so an admin can never lock themselves out.
 *  - permissions_admin.php itself is hard-coded admin-only.
 *
 * USAGE (auth.php already includes this file on every protected page):
 *      if (can('page.forecast')) { ... }        // feature check
 *      perm_require('page.forecast');           // gate a whole page (redirects)
 */

/**
 * The full permission catalog: every key, grouped for the admin UI,
 * with bilingual labels + descriptions and built-in defaults.
 * 'd' = defaults for [admin, manager, sales] (1 = allowed, 0 = denied).
 * 'locked' => 'all'   : always allowed for everyone (not editable).
 * 'locked' => 'admin' : always allowed for the admin role (editable for others).
 */
function perm_catalog(): array
{
    return [

        'pages_main' => [
            'ar' => 'الصفحات الأساسية', 'en' => 'Main Pages', 'icon' => '🏠',
            'perms' => [
                'page.dashboard' => [
                    'ar' => 'الرئيسية (لوحة التحكم)', 'en' => 'Dashboard (home page)',
                    'dar' => 'الصفحة الرئيسية بعد تسجيل الدخول — مسموحة للجميع دائماً',
                    'den' => 'The home page after login — always allowed for everyone',
                    'd' => [1, 1, 1], 'locked' => 'all',
                ],
                'page.stock_report' => [
                    'ar' => 'تقرير المخزون', 'en' => 'Stock Report page',
                    'dar' => 'فتح صفحة تقرير المخزون وعرضها', 'den' => 'Open and view the stock report page',
                    'd' => [1, 1, 1],
                ],
                'page.prices' => [
                    'ar' => 'صفحة الأسعار', 'en' => 'Prices page',
                    'dar' => 'فتح صفحة إدارة الأسعار', 'den' => 'Open the price management page',
                    'd' => [1, 1, 1],
                ],
                'page.attendance' => [
                    'ar' => 'صفحة البصمة (الحضور)', 'en' => 'Attendance (Basma) page',
                    'dar' => 'تسجيل الحضور والانصراف', 'den' => 'Check-in / check-out page',
                    'd' => [1, 1, 1],
                ],
                'page.vehicle_timeline' => [
                    'ar' => 'رحلة السيارة (التايم لاين)', 'en' => 'Vehicle timeline (journey)',
                    'dar' => 'عرض تاريخ وحركات أي سيارة', 'den' => 'View any vehicle\'s history and movements',
                    'd' => [1, 1, 1],
                ],
            ],
        ],

        'pages_vehicles' => [
            'ar' => 'صفحات إدارة السيارات', 'en' => 'Vehicle Management Pages', 'icon' => '🚗',
            'perms' => [
                'page.add_vehicle' => [
                    'ar' => 'إضافة سيارة', 'en' => 'Add Vehicle page',
                    'dar' => 'إضافة سيارات جديدة للمخزون', 'den' => 'Add new vehicles to inventory',
                    'd' => [1, 1, 0],
                ],
                'page.edit_vehicle' => [
                    'ar' => 'تعديل سيارة', 'en' => 'Edit Vehicle page',
                    'dar' => 'تعديل بيانات سيارة موجودة', 'den' => 'Edit an existing vehicle\'s data',
                    'd' => [1, 1, 0],
                ],
                'page.transfer_vehicle' => [
                    'ar' => 'نقل سيارة بين الفروع', 'en' => 'Transfer Vehicle page',
                    'dar' => 'نقل السيارات بين الفروع', 'den' => 'Move vehicles between branches',
                    'd' => [1, 1, 0],
                ],
                'page.sold_vehicle' => [
                    'ar' => 'بيع سيارة', 'en' => 'Sell Vehicle page',
                    'dar' => 'تسجيل بيع سيارة (عميل أو تاجر)', 'den' => 'Record a vehicle sale (customer or dealer)',
                    'd' => [1, 1, 0],
                ],
                'page.receive_shipment' => [
                    'ar' => 'استلام شحنة', 'en' => 'Receive Shipment page',
                    'dar' => 'استلام شحنات سيارات جديدة', 'den' => 'Receive new vehicle shipments',
                    'd' => [1, 1, 0],
                ],
                'page.qr_stickers' => [
                    'ar' => 'ملصقات QR', 'en' => 'QR Stickers page',
                    'dar' => 'طباعة ملصقات QR للسيارات', 'den' => 'Print QR stickers for vehicles',
                    'd' => [1, 1, 0],
                ],
                'page.consignment_return' => [
                    'ar' => 'إرجاع سيارة أمانة', 'en' => 'Consignment (Amana) return',
                    'dar' => 'إرجاع سيارة أمانة إلى المخزون', 'den' => 'Return a consignment car back to stock',
                    'd' => [1, 1, 0],
                ],
            ],
        ],

        'pages_admin' => [
            'ar' => 'صفحات الإدارة والتقارير', 'en' => 'Admin & Reports Pages', 'icon' => '📊',
            'perms' => [
                'page.sold_inventory' => [
                    'ar' => 'السيارات المباعة', 'en' => 'Sold Inventory page',
                    'dar' => 'عرض كل السيارات المباعة وتفاصيلها', 'den' => 'View all sold vehicles and their details',
                    'd' => [1, 0, 0],
                ],
                'sold.revert' => [
                    'ar' => 'إرجاع سيارة مباعة للمخزون', 'en' => 'Return a sold car to inventory',
                    'dar' => 'إلغاء البيع وإرجاع السيارة كسيارة متاحة في المخزون (يظهر في رحلة السيارة أنها بيعت ثم رجعت)',
                    'den' => 'Cancel a sale and put the car back as available stock (the timeline shows it was sold then returned)',
                    'd' => [1, 0, 0],
                ],
                'sold.edit' => [
                    'ar' => 'تعديل بيانات المشتري في المبيعات', 'en' => 'Edit the buyer on a sale',
                    'dar' => 'تعديل اسم/هاتف العميل أو اسم التاجر الذي بيعت له السيارة',
                    'den' => 'Edit the customer name/phone or dealer name a car was sold to',
                    'd' => [1, 0, 0],
                ],
                'page.sales_analytics' => [
                    'ar' => 'تحليلات المبيعات', 'en' => 'Sales Analytics page',
                    'dar' => 'إحصائيات وتحليلات المبيعات', 'den' => 'Sales statistics and analytics',
                    'd' => [1, 0, 0],
                ],
                'page.forecast' => [
                    'ar' => 'التوقعات', 'en' => 'Forecast page',
                    'dar' => 'توقعات المبيعات والمخزون', 'den' => 'Sales and stock forecasting',
                    'd' => [1, 0, 0],
                ],
                'page.incoming_cars' => [
                    'ar' => 'السيارات الواردة (الألوان)', 'en' => 'Incoming Cars page',
                    'dar' => 'متابعة السيارات الواردة والألوان', 'den' => 'Track incoming cars and colors',
                    'd' => [1, 0, 0],
                ],
                'page.price_history' => [
                    'ar' => 'سجل تغيّرات الأسعار', 'en' => 'Price History page',
                    'dar' => 'عرض تاريخ تعديلات الأسعار', 'den' => 'View the history of price changes',
                    'd' => [1, 0, 0],
                ],
                'page.attendance_admin' => [
                    'ar' => 'سجل البصمة (إدارة)', 'en' => 'Attendance Log (admin)',
                    'dar' => 'عرض سجل حضور كل الموظفين', 'den' => 'View all employees\' attendance log',
                    'd' => [1, 0, 0],
                ],
                'page.attendance_export' => [
                    'ar' => 'تصدير تقارير البصمة', 'en' => 'Attendance export',
                    'dar' => 'تصدير تقارير الحضور (إكسل)', 'den' => 'Export attendance reports (Excel)',
                    'd' => [1, 0, 0],
                ],
                'page.branch_geo' => [
                    'ar' => 'مواقع الفروع (GPS)', 'en' => 'Branch locations (GPS)',
                    'dar' => 'ضبط إحداثيات الفروع للبصمة', 'den' => 'Configure branch GPS coordinates for attendance',
                    'd' => [1, 0, 0],
                ],
            ],
        ],

        'pages_users' => [
            'ar' => 'إدارة المستخدمين', 'en' => 'User Management', 'icon' => '👥',
            'perms' => [
                'page.users' => [
                    'ar' => 'صفحة المستخدمين', 'en' => 'Users page',
                    'dar' => 'عرض قائمة المستخدمين — لا يمكن سحبها من الأدمن أبداً',
                    'den' => 'View the users list — can never be taken away from admins',
                    'd' => [1, 0, 0], 'locked' => 'admin',
                ],
                'page.add_user' => [
                    'ar' => 'إضافة مستخدم', 'en' => 'Add User',
                    'dar' => 'إنشاء حسابات مستخدمين جديدة', 'den' => 'Create new user accounts',
                    'd' => [1, 0, 0],
                ],
                'page.edit_user' => [
                    'ar' => 'تعديل مستخدم', 'en' => 'Edit User',
                    'dar' => 'تعديل بيانات ودور أي مستخدم', 'den' => 'Edit any user\'s data and role',
                    'd' => [1, 0, 0],
                ],
                'page.reset_user_password' => [
                    'ar' => 'إعادة تعيين كلمة المرور', 'en' => 'Reset user password',
                    'dar' => 'إعادة تعيين كلمة مرور أي مستخدم', 'den' => 'Reset any user\'s password',
                    'd' => [1, 0, 0],
                ],
                'action.toggle_user' => [
                    'ar' => 'تفعيل / تعطيل مستخدم', 'en' => 'Enable / disable user',
                    'dar' => 'تفعيل أو تعطيل حسابات المستخدمين', 'den' => 'Activate or deactivate user accounts',
                    'd' => [1, 0, 0],
                ],
                'page.user_activity' => [
                    'ar' => 'نشاط المستخدمين', 'en' => 'User activity page',
                    'dar' => 'عرض سجل نشاط أي مستخدم', 'den' => 'View any user\'s activity log',
                    'd' => [1, 0, 0],
                ],
            ],
        ],

        'dashboard' => [
            'ar' => 'تفاصيل لوحة التحكم', 'en' => 'Dashboard Details', 'icon' => '🎛️',
            'perms' => [
                'dash.stat_total' => [
                    'ar' => 'كارت إجمالي السيارات', 'en' => 'Total-vehicles stat card',
                    'dar' => 'رؤية العدد الإجمالي للسيارات (بدلاً من القفل ••••)',
                    'den' => 'See the real total vehicle count (instead of the •••• lock)',
                    'd' => [1, 0, 0],
                ],
                'dash.stat_sold' => [
                    'ar' => 'كارت السيارات المباعة', 'en' => 'Sold-vehicles stat card',
                    'dar' => 'رؤية عدد المبيعات والرابط لصفحة المباعة',
                    'den' => 'See the sold count and the link to the sold inventory',
                    'd' => [1, 0, 0],
                ],
                'dash.stat_amana' => [
                    'ar' => 'كارت سيارات الأمانة', 'en' => 'Consignment (Amana) stat card',
                    'dar' => 'رؤية عدد سيارات الأمانة', 'den' => 'See the consignment vehicles count',
                    'd' => [1, 1, 0],
                ],
                'dash.amana_section' => [
                    'ar' => 'قسم سيارات الأمانة', 'en' => 'Consignment (Amana) section',
                    'dar' => 'رؤية شريط سيارات الأمانة وتفاصيلها (التاجر، التاريخ، المؤقت)',
                    'den' => 'See the consignment strip and its details (dealer, date, timer)',
                    'd' => [1, 1, 0],
                ],
                'dash.amana_actions' => [
                    'ar' => 'أزرار الأمانة (بيع / إرجاع)', 'en' => 'Amana actions (sell / return)',
                    'dar' => 'أزرار "تم البيع" و"إرجاع السيارة" في شريط الأمانة',
                    'den' => 'The "Mark Sold" and "Return Car" buttons in the amana strip',
                    'd' => [1, 1, 0],
                ],
                'dash.sold_section' => [
                    'ar' => 'قسم السيارات المباعة', 'en' => 'Sold vehicles section',
                    'dar' => 'رؤية كروت السيارات المباعة أسفل الرئيسية',
                    'den' => 'See the sold vehicle cards at the bottom of the dashboard',
                    'd' => [1, 0, 0],
                ],
                'dash.quotes_edit' => [
                    'ar' => 'تعديل العبارات التحفيزية', 'en' => 'Edit motivational quotes',
                    'dar' => 'زر ✏️ لتعديل عبارات البانر المتحرك', 'den' => 'The ✏️ button that edits the animated quote banner',
                    'd' => [1, 0, 0],
                ],
                'dash.btn_edit' => [
                    'ar' => 'زر تعديل على كارت السيارة', 'en' => 'Edit button on car card',
                    'dar' => 'إظهار زر "تعديل" على كروت السيارات', 'den' => 'Show the "Edit" button on vehicle cards',
                    'd' => [1, 1, 0],
                ],
                'dash.btn_transfer' => [
                    'ar' => 'زر نقل على كارت السيارة', 'en' => 'Transfer button on car card',
                    'dar' => 'إظهار زر "نقل" على كروت السيارات', 'den' => 'Show the "Transfer" button on vehicle cards',
                    'd' => [1, 1, 0],
                ],
                'dash.btn_sell' => [
                    'ar' => 'زر بيع على كارت السيارة', 'en' => 'Sell button on car card',
                    'dar' => 'إظهار زر "بيع" على كروت السيارات', 'den' => 'Show the "Sell" button on vehicle cards',
                    'd' => [1, 1, 0],
                ],
                'dash.wa_customer' => [
                    'ar' => 'واتساب عميل', 'en' => 'Customer WhatsApp button',
                    'dar' => 'زر مشاركة السيارة مع عميل عبر واتساب', 'den' => 'Share a car with a customer via WhatsApp',
                    'd' => [1, 1, 1],
                ],
                'dash.wa_customer_price' => [
                    'ar' => 'سعر العميل في رسالة الواتساب', 'en' => 'Customer price in WhatsApp',
                    'dar' => 'تضمين سعر البيع تلقائياً في رسالة الواتساب',
                    'den' => 'Auto-include the selling price in the WhatsApp message',
                    'd' => [1, 1, 0],
                ],
                'dash.wa_dealer' => [
                    'ar' => 'واتساب تاجر', 'en' => 'Dealer WhatsApp button',
                    'dar' => 'زر مشاركة السيارة مع تاجر (بسعر التاجر)', 'den' => 'Share a car with a dealer (with trade price)',
                    'd' => [1, 1, 0],
                ],
                'dash.nav_attendance' => [
                    'ar' => 'زر البصمة في الشريط السفلي', 'en' => 'Attendance button in bottom nav',
                    'dar' => 'إظهار زر البصمة في شريط التنقل السفلي (افتراضياً يظهر لغير الأدمن)',
                    'den' => 'Show the Basma button in the bottom nav (default: non-admins only)',
                    'd' => [0, 1, 1],
                ],
            ],
        ],

        'prices' => [
            'ar' => 'تفاصيل صفحة الأسعار', 'en' => 'Prices Page Details', 'icon' => '💲',
            'perms' => [
                'prices.edit' => [
                    'ar' => 'تعديل الأسعار', 'en' => 'Edit prices',
                    'dar' => 'إضافة وتعديل وحذف الأسعار والموديلات', 'den' => 'Add, edit and delete prices and models',
                    'd' => [1, 1, 0],
                ],
                'prices.trade_price' => [
                    'ar' => 'رؤية سعر التاجر', 'en' => 'See trade price',
                    'dar' => 'رؤية عمود سعر التاجر (يظهر مقفولاً 🔒 لمن لا يملك الصلاحية)',
                    'den' => 'See the trade price column (shows locked 🔒 without this permission)',
                    'd' => [1, 1, 0],
                ],
            ],
        ],

        'features' => [
            'ar' => 'ميزات أخرى', 'en' => 'Other Features', 'icon' => '⚙️',
            'perms' => [
                'stock.amana' => [
                    'ar' => 'الأمانة في تقرير المخزون', 'en' => 'Amana in stock report',
                    'dar' => 'رؤية قسم سيارات الأمانة داخل تقرير المخزون',
                    'den' => 'See the consignment section inside the stock report',
                    'd' => [1, 1, 0],
                ],
                'timeline.amana' => [
                    'ar' => 'الأمانة في رحلة السيارة', 'en' => 'Amana in vehicle timeline',
                    'dar' => 'رؤية سيارات وأحداث الأمانة في صفحة الرحلة',
                    'den' => 'See consignment cars and events in the timeline page',
                    'd' => [1, 1, 0],
                ],
                'reserve.create' => [
                    'ar' => 'حجز السيارة', 'en' => 'Reserve a car',
                    'dar' => 'زر "حجز السيارة" على كارت السيارة — يحجزها بضغطة واحدة بدون إدخال أي بيانات، وتظهر ذهبية في كل الصفحات',
                    'den' => 'The "Reserve" button on a car card — one click, no data entry; the car turns gold everywhere',
                    'd' => [1, 1, 0],
                ],
                'reserve.cancel' => [
                    'ar' => 'إلغاء حجز السيارة', 'en' => 'Cancel a reservation',
                    'dar' => 'زر "إلغاء الحجز" — يرجّع السيارة المحجوزة لحالتها العادية',
                    'den' => 'The "Cancel reservation" button — puts a reserved car back to normal',
                    'd' => [1, 1, 0],
                ],
                'amana.manage' => [
                    'ar' => 'إدارة الأمانة عند البيع', 'en' => 'Manage amana in sales',
                    'dar' => 'إخراج سيارة أمانة أو إغلاقها كبيع من صفحة البيع',
                    'den' => 'Put a car out on consignment or close it as a sale from the sell page',
                    'd' => [1, 1, 0],
                ],
                'qr.manage' => [
                    'ar' => 'أزرار الإدارة في كارت QR', 'en' => 'Manage buttons on QR card',
                    'dar' => 'أزرار تعديل / نقل / بيع / طباعة عند مسح QR السيارة',
                    'den' => 'Edit / transfer / sell / print buttons when scanning a car QR',
                    'd' => [1, 1, 0],
                ],
                'chat.manager_data' => [
                    'ar' => 'بيانات الإدارة في المساعد الذكي', 'en' => 'Manager data in chatbot',
                    'dar' => 'رؤية أسعار التاجر وأرقام المبيعات في المساعد الذكي',
                    'den' => 'See trade prices and sales numbers in the AI assistant',
                    'd' => [1, 1, 0],
                ],
            ],
        ],

    ];
}

/** Built-in defaults as role => [key => bool]. */
function perm_defaults(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = ['admin' => [], 'manager' => [], 'sales' => []];
    foreach (perm_catalog() as $group) {
        foreach ($group['perms'] as $key => $p) {
            $map['admin'][$key]   = (bool)$p['d'][0];
            $map['manager'][$key] = (bool)$p['d'][1];
            $map['sales'][$key]   = (bool)$p['d'][2];
        }
    }
    return $map;
}

/** Flat list of every permission key. */
function perm_all_keys(): array
{
    return array_keys(perm_defaults()['admin']);
}

/** Auto-create the override tables (safe to call repeatedly). */
function perm_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS role_permissions (
                role       VARCHAR(32)  NOT NULL,
                perm_key   VARCHAR(64)  NOT NULL,
                allowed    TINYINT(1)   NOT NULL DEFAULT 0,
                updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (role, perm_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_permissions (
                user_id    INT          NOT NULL,
                perm_key   VARCHAR(64)  NOT NULL,
                allowed    TINYINT(1)   NOT NULL DEFAULT 0,
                updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, perm_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        // If table creation fails, the site still runs on built-in defaults.
        error_log('permissions: table create failed: ' . $e->getMessage());
    }
}

/** Stored overrides for one role: [key => bool]. */
function perm_role_overrides(PDO $pdo, string $role): array
{
    perm_ensure_tables($pdo);
    $out = [];
    try {
        $stmt = $pdo->prepare("SELECT perm_key, allowed FROM role_permissions WHERE role = ?");
        $stmt->execute([$role]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['perm_key']] = (bool)$r['allowed'];
        }
    } catch (Exception $e) {
        error_log('permissions: role read failed: ' . $e->getMessage());
    }
    return $out;
}

/** Stored overrides for one user: [key => bool]. */
function perm_user_overrides(PDO $pdo, int $userId): array
{
    perm_ensure_tables($pdo);
    $out = [];
    try {
        $stmt = $pdo->prepare("SELECT perm_key, allowed FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['perm_key']] = (bool)$r['allowed'];
        }
    } catch (Exception $e) {
        error_log('permissions: user read failed: ' . $e->getMessage());
    }
    return $out;
}

/** Apply the safety locks to a computed permission map. */
function perm_apply_locks(array $eff, string $role): array
{
    foreach (perm_catalog() as $group) {
        foreach ($group['perms'] as $key => $p) {
            $lock = $p['locked'] ?? '';
            if ($lock === 'all' || ($lock === 'admin' && $role === 'admin')) {
                $eff[$key] = true;
            }
        }
    }
    return $eff;
}

/** Effective permissions for any user: default → role override → user override → locks. */
function perm_effective(PDO $pdo, int $userId, string $role): array
{
    $defaults = perm_defaults();
    $eff = $defaults[$role] ?? $defaults['sales'];   // unknown role = safest defaults
    foreach (perm_role_overrides($pdo, $role) as $k => $v) {
        if (array_key_exists($k, $eff)) $eff[$k] = $v;
    }
    foreach (perm_user_overrides($pdo, $userId) as $k => $v) {
        if (array_key_exists($k, $eff)) $eff[$k] = $v;
    }
    return perm_apply_locks($eff, $role);
}

/** Effective permissions for a role WITHOUT user overrides (used by the admin UI). */
function perm_effective_role(PDO $pdo, string $role): array
{
    $defaults = perm_defaults();
    $eff = $defaults[$role] ?? $defaults['sales'];
    foreach (perm_role_overrides($pdo, $role) as $k => $v) {
        if (array_key_exists($k, $eff)) $eff[$k] = $v;
    }
    return perm_apply_locks($eff, $role);
}

/** Permission check for the CURRENTLY LOGGED-IN user. */
function can(string $key): bool
{
    global $pdo;
    static $cache = null;
    if (!isset($_SESSION['user_id']) || !isset($pdo)) return false;
    if ($cache === null) {
        $cache = perm_effective($pdo, (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? 'sales'));
    }
    return !empty($cache[$key]);
}

/** Gate a whole page: redirect to the dashboard if the permission is missing. */
function perm_require(string $key): void
{
    if (can($key)) return;
    $lang = $_GET['lang'] ?? 'ar';
    if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
    header('Location: dashboard.php?lang=' . $lang . '&denied=1');
    exit;
}
