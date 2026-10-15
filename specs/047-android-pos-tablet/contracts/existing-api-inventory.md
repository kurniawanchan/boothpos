# INTERNAL — BoothPOS/Warkop WJM API inventory for tablet POS (verified against routes/api.php + controllers, branch 047)

Base: /api/v1. JSON. Money = string "12345.00". Pagination envelope {data, meta{current_page,per_page,total,last_page}}.
Errors: 401 auth, 403 role/outlet (code outlet_forbidden), 404, 409 business conflict (also TabConflictException with extra fields e.g. existing_tab_id), 422 validation/InputRejected (outlet_invalid), 423 install not activated (all routes except /license/*), 503 {code:schema_outdated}.
Gate model: role.menu_keys (canAccessMenu) + PermissionCatalog action perms (menu.item.manage|86, menu.price.*, menu.release.publish, menu.recipe.edit, stock.transfer.*). isOwnerOrAdmin() == canAccessMenu('settings').

## Auth / scope
- POST /auth/login {username,password} throttle 5/min -> 200 {token (Sanctum plain-text bearer, name 'pos-session', expiration null = never expires), user{id,name,username,role,menu_keys[],permissions[],is_active,language,photo_url}}; 401 same msg for bad user/pass; 401 inactive. NO PIN concept in code.
- POST /auth/logout (deletes current token, 204); GET /auth/me; PUT /auth/language; PUT /auth/password; POST /auth/photo.
- GET /me/scope -> {outlets[{id,code,name}], selected, can_see_all, multi}.
- Outlet selection: header X-Outlet-Id (absent = all permitted; non-digit 422 outlet_invalid; foreign 403 outlet_forbidden). Body outlet_id never trusted except POST /sessions (must be permitted). Ordering outlet = open-shift outlet (server stamps).
- GET /license/status, POST /license/activate (no auth). Everything else 423 until activated.
## Shift
- GET /sessions/current (404 if none; includes outlet). POST /sessions {opening_cash, notes?, outlet_id?, opening_cash_entries[]?} -> 201; 409 if user already has open shift; 409 outlet_unassigned; 422 if several outlets and none chosen. Any authenticated user (no menu gate).
- POST /sessions/{id}/close {closing_cash, notes?}: owner of shift or admin; 409 + open_tabs[] if open tabs exist; computes expected_cash/cash_difference.
- GET /sessions/{id}/summary -> order_count,total_sales,by_method[verified only],opening_cash_entries.
## Floor/tables (menu 'floor'; setup 'dining_setup')
- GET /floor?version= -> {areas[{area,tables[{id,name,seats,is_active,state free|occupied,tab{id,order_number,total_amount,paid_amount,opened_at,session_id,ready_count,unsent_count}}]}],takeaway_tabs[],version} ; multi-outlet: {outlets[{id,code,name,areas,takeaway_tabs}]}; unchanged -> {unchanged:true,version}. Poll ~3s.
- apiResource /dining-tables (dining_setup), /stations (index also kitchen), /modifier-groups (+PUT attachments).
## Menu
- GET /products?pos=1&with_variants=1&with_modifiers=1&search=&category_id[]=&per_page<=100 (menu 'products' via ProductPolicy::viewAny — VERIFY cashier role has it; ASSUMPTION) -> ProductResource{id,name,category_id/name,image_url,is_active,station_id,variants[...],modifier_groups[{id,name,selection_type,is_required,min_select,max_select,options[{id,name,price_delta}]}]}. pos=1 = shift outlet menu/price.
- GET /variants/lookup?q=&limit<=50&pos=1 -> [{variant_id,sku,label,category_name,sell_price,outlet_price,current_stock,outlet_stock,image_url}] (no auth gate beyond login).
- GET /menu/availability?pos=1&since=<etag> -> 304 or {etag,menu_version,sold_out[{product_id,outlet_id,variant_ids,reason_code,expires_at}],discontinued_ids}. Poll ~5s. 86 writes: POST/DELETE /products/{id}/sold-out (perm menu.item.86).
- GET /categories, /payment-channels (account_number masked unless settings menu; qr_image_url), /customers?search= (viewAny), POST /customers.
## Tabs / lines (all need menu 'floor')
- GET /tabs?session_id=&table_id= ; POST /tabs {session_id*, table_id?, tab_label?, customer_id?, local_ref? uuid (idempotent; replay returns existing)} 201; 409 table_occupied {existing_tab_id}.
- GET /tabs/{id} -> TabResource{id,order_number,status,service_type,table,tab_label,opened_by,session_id,customer_id,subtotal,discount_amount,total_amount,paid_amount,change_amount,balance,lines[{id,variant_id,name,qty,unit_price,line_total,modifiers,note,sent,prep_status,ticket_item_id,station}],payments[{id,method,amount,verification,paid_at}]}. 404 for retail/voided/merged.
- POST /tabs/{id}/items {variant_id,qty 1-999,note<=255,modifier_option_ids[]} 201 (NOT idempotent; stock deducted at add; 409 not orderable/insufficient stock). PATCH /tabs/{id}/items/{item} {qty?,note?,modifier_option_ids?} (only unsent lines). DELETE /tabs/{id}/items/{item} {reason} — unsent: delete; sent: cancel, reason REQUIRED (422), after prep started only owner/admin (403).
- POST /tabs/{id}/move {table_id}; /merge {source_tab_id}; /send (-> tickets per station, round_no; 422/409 nothing_to_send); DELETE /tabs/{id} (empty tab only); POST /tabs/{id}/void {reason} owner/admin only, 409 if payments; POST /tabs/{id}/handover {session_id} owner/admin only.
## Kitchen / KOT (menu 'kitchen' or 'floor')
- POST /tabs/{id}/send -> {tab, tickets[TicketResource]}. TicketResource{id,round_no,sent_at,station{id,name},order{id,order_number},table{id,name},tab_label,waiting_seconds,items[{id,name,qty,modifiers,note,status new|preparing|ready|served,*_at,cancelled_at,cancel_reason}]}.
- GET /tickets/{id} (print view). GET /stations/{id}/tickets?version= (kitchen menu; unchanged shape). PATCH /ticket-items/{id}/status {status preparing|ready|served}: strict forward chain; preparing/ready need kitchen; served kitchen or floor; 409 bad transition, 403.
- No server-side printing/ESC-POS; client prints from ticket/receipt JSON. Items without station auto 'ready'.
## Payment / tender
- POST /tabs/{id}/payments {client_ref* uuid, discount_amount?, payments*[{method cash|bank_transfer|qr_ewallet, channel_id? (required non-cash), amount>0, proof_token? uuid, notes}]}: idempotent via client_ref (submission_ref) -> returns current tab on replay. Partial allowed (stays open, purpose down_payment); completes when paid>=total -> status completed, change computed (409 if change > cash). discount_amount flat Rupiah <= subtotal, ANY floor user, no reason/approval.
- POST /tabs/{id}/split {client_ref*, items*[{item_id,qty}], discount_amount?, payments*} -> 201 {paid_order,tab} / 200 replay; 409 if tab already has payments.
- POST /payment-proofs multipart {file jpg/png<=5MB, captured_via webcam|upload} -> {proof_token}; upload before payment.
- POST /orders {session_id,local_ref* uuid,customer_id?,discount_amount?,items*[{variant_id,qty,discount_amount?}],payments*} = retail one-shot (idempotent by local_ref; 201; 409 conflicts; partial pay needs customer_id). Later payments: POST /orders/{id}/payments {method,amount,client_ref?,...}; DELETE payment owner/admin; PATCH .../confirmation; POST .../verify (not own payment unless admin). POST /orders/{id}/void {reason*} needs settings menu (owner/admin). GET /orders (paginated, completed|voided only), GET /orders/{id}.
- GET /orders/{id}/receipt -> store_name/contact/address/logo_url/footer, order_number, service_type, table, tab_label, outlet_name, cashier_name, created_at, items[{name,qty,price,line_total,modifiers,note}], subtotal,discount_amount,total_amount,payment_summary[],change_amount,paid_amount,balance_amount,payment_status. 409 if still open tab.
- pos-drafts apiResource (index,store,show,destroy): server-side cart draft JSON.
## Reports/dashboard
- /dashboard/* menu 'dashboard'; /reports/sales etc. menu 'reports'; /stock/low, /stock/balances.
## Settings
- GET /settings/features -> multi_outlet_enabled,outlet_count,system_mode demo|live,app_name,theme_accent_color,receipt_footer_text,receipt_show_logo,store_name,menu_release_enabled,store_timezone,business_day_cutoff,schema_update_required.
## NOT present (verified by grep)
Device registry, heartbeat, PIN login, offline outbox/batch sync endpoint, approval-for-discount/void (the /approvals endpoints are menu price changes only), line price override, tip/service charge/tax endpoints, server-side print, websockets/push (polling only: /floor, /menu/availability, /stations/{id}/tickets).
Offline-friendly pieces: local_ref (tabs open, orders), client_ref (tab payments, split, order payments), version/etag polling. Tab line add/update/send have no idempotency key.
## Discrepancies
OpenAPI (docs/openapi-pos-mvp.yaml) has NO /tabs, /floor, /tickets, /ticket-items, /stations, /dining-tables, /modifier-groups paths; still lists /artists and /reports/stock-by-artist (renamed to outlets in 045: routes now /outlets, /reports/stock-by-outlet). CLAUDE.md says Sanctum tokens; config expiration null. OrderController::index excludes open tabs.
## /android
Feature-008 scaffold: Kotlin shell (6 files, ~780 lines: MainActivity WebView -> 127.0.0.1, RuntimeForegroundService launching bundled PHP+MariaDB, FirstRunSetup, BackupRestoreBridge). applicationId com.boothpos.installer, minSdk 26, targetSdk 34, arm64-v8a. Source-only: runtime binaries not vendored, never built (no build dir), not a native POS UI; no API client code.
