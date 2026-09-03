# Feature Specification: UX Enhancements — Product/POS Filters, Menu Styling, Dashboard, User Profile

**Feature Branch**: `005-ux-enhancements-dashboard`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "Update: Product dan POS page: filter artist and categories dijadikan dropdown, bisa ketik untuk search di dropdown, bisa select all, multiple atau single. Menu: ubah bahasa indonesia untuk Purchase menjadi Pembelian; ubah warna menu yang ada submenu supaya sama dengan menu yg tidak ada submenu. Dashboard: tambahkan shortcut penting; tambahkan filter hari di penjualan per hari; tambahkan statistik berupa grafik untuk penjualan per kategori, per artist, per event; tambahkan link yg berhubungan untuk setiap section; tambahkan juga statistik lain yang diperlukan. User login profile: Detail profil user login, bisa ganti password dan foto"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Searchable multi-select filters on Products and POS (Priority: P1)

A cashier or inventory staff working the Products management screen or the POS screen needs to narrow a long product list down to one artist, one category, several artists/categories at once, or everything at all — and wants to type to jump to the right entry instead of scrolling a long dropdown list.

**Why this priority**: This replaces the current single-select dropdown filter that is already in daily use on the two highest-traffic screens in the app (POS is used every transaction; Products is used constantly by inventory staff). It's the most requested and highest-frequency-use change in this batch.

**Independent Test**: Open Products or POS, open the Artist filter, type a partial name, see the list narrow, pick multiple artists, confirm the product list reflects the combined selection; repeat for Category; confirm an explicit "All" selection clears back to the unfiltered view.

**Acceptance Scenarios**:

1. **Given** the Products or POS screen is open, **When** the user opens the Artist (or Category) filter dropdown, **Then** it presents a searchable list of all active artists (or categories) with checkboxes and an "All" option.
2. **Given** the dropdown is open, **When** the user types a search term, **Then** the visible options are filtered to matches (case-insensitive, partial match) while the search box keeps focus.
3. **Given** no artists are explicitly selected, **When** the dropdown is opened, **Then** "All" is shown as selected and the product list is unfiltered by that axis.
4. **Given** the user selects one or more specific artists, **When** the dropdown is closed, **Then** "All" is automatically deselected and only products matching the selected artist(s) are shown; selecting "All" afterward clears the specific selections back to unfiltered.
5. **Given** artist and category filters are both set to specific selections, **When** the product list is displayed, **Then** it satisfies both filters simultaneously (AND across axes, OR within an axis's multiple selections).
6. **Given** a filter selection is made on the Products screen, **When** the user navigates away and back within the same session, **Then** the previous behavior of resetting filters on navigation is preserved (no new persistence requirement introduced by this change).

---

### User Story 2 - Dashboard shortcuts, analytics, and drill-through links (Priority: P2)

An owner or admin opens the dashboard each morning to gauge how the store is doing and wants to (a) jump straight into common tasks, (b) see sales broken down by day/category/artist/event as charts instead of just totals, and (c) click from a dashboard section into the fuller screen that explains it.

**Why this priority**: High business value (faster daily decisions, faster access to common actions) but touches only the dashboard screen and read-only reporting data — lower risk and narrower blast radius than the filter rework in US1, so it can ship independently after or alongside it.

**Independent Test**: Open the dashboard as an owner/admin; use a shortcut tile to land on the corresponding action screen; change the day filter on the daily-sales panel and confirm the figures update; view the category/artist/event charts for the current event; click a section's "view more" link and land on the corresponding full report/list screen.

**Acceptance Scenarios**:

1. **Given** the dashboard is open, **When** it renders, **Then** it shows a row of shortcut tiles for the most common actions (new sale, new pre-order, stock adjustment, add product) that each navigate directly to that action's screen, gated by the viewer's existing role permissions for that action.
2. **Given** the dashboard's "sales per day" panel, **When** the user changes the day/date-range filter, **Then** the panel re-fetches and displays sales figures scoped to the selected period.
3. **Given** an active event with sales recorded, **When** the dashboard renders its analytics section, **Then** it shows chart-based breakdowns of sales by category, by artist, and by event, scoped to the DEMO/LIVE mode currently active.
4. **Given** any dashboard section (shortcuts, daily sales, category/artist/event breakdown, other statistics), **When** the user looks at that section, **Then** it offers a link to the related full screen (e.g., Reports, Sales, Products) for deeper detail.
5. **Given** a role without permission for a given shortcut's target screen (e.g., a cashier and "add product"), **When** the dashboard renders, **Then** that shortcut is omitted rather than shown disabled or erroring on click.
6. **Given** no sales exist yet for the selected period/event, **When** the analytics charts render, **Then** they show an explicit empty state rather than a blank or broken chart.

---

### User Story 3 - User profile: view details, change password, change photo (Priority: P2)

Any logged-in user wants a place to see their own account details and to change their own password and profile photo without asking an admin.

**Why this priority**: Self-service account management is a standalone, well-understood capability independent of the other three areas, usable by every role, and doesn't depend on US1/US2/US4 shipping first.

**Independent Test**: Log in as any role, open the profile page, view name/username/role, change password with correct current-password confirmation, upload a new photo, confirm both persist and the new photo appears in the app header.

**Acceptance Scenarios**:

1. **Given** a logged-in user, **When** they open their profile page, **Then** they see their name, username, role, and current photo.
2. **Given** the profile page, **When** the user submits a new password along with their current password, **Then** the password is changed only if the current password is correct and the new password meets the existing account password policy; the current session remains valid.
3. **Given** the user submits a new password without correct current-password confirmation, **When** the form is submitted, **Then** the change is rejected with a clear error and no password change occurs.
4. **Given** the profile page, **When** the user uploads a new photo in a supported image format and size, **Then** the photo replaces their previous photo and is reflected wherever their avatar is shown in the app.
5. **Given** the user uploads a file that is not a supported image or exceeds the size limit, **When** they submit it, **Then** the upload is rejected with a clear error and the existing photo is unchanged.

---

### User Story 4 - Menu label and styling corrections (Priority: P3)

Any logged-in user viewing the sidebar sees "Pembelian" (not the English "Purchase") for that Indonesian-language menu group, and menu items that expand a submenu look visually consistent (same color/weight as items without one) so the sidebar doesn't look like two different design systems.

**Why this priority**: Small, low-risk, purely cosmetic/copy fix confined to the sidebar component — lowest effort and lowest business impact of the four areas, safe to do last.

**Independent Test**: Open the sidebar and confirm the group previously labeled "Purchase" now reads "Pembelian"; visually compare a parent menu item that has a submenu against one that doesn't and confirm matching color treatment in both expanded and collapsed states.

**Acceptance Scenarios**:

1. **Given** the sidebar is rendered, **When** the user views the group containing Vendor/Bahan Baku, **Then** its label reads "Pembelian" instead of "Purchase".
2. **Given** the sidebar is rendered, **When** the user compares a top-level item with a submenu (e.g., Inventaris, Pembelian, Pengaturan) to one without (e.g., Sesi Kasir), **Then** both use the same color treatment for their default, hover, and active states.
3. **Given** a submenu is expanded, **When** its parent item is in the active/expanded state, **Then** the parent's styling remains visually consistent with a non-parent active menu item (no mismatched accent color).

---

### Edge Cases

- What happens when a user searches the artist/category dropdown for a term matching zero entries? → Show an explicit "no results" state within the dropdown, "All" remains available.
- What happens when an artist or category is deactivated while selected in a filter? → The selection is dropped from the active filter set on next filter-affecting fetch (inactive artists don't produce filterable products in either screen already).
- What happens to dashboard charts when the active DEMO/LIVE mode is switched? → Charts and shortcuts reflect only the newly active mode's data, consistent with existing mode-scoping behavior elsewhere in the app.
- What happens if a cashier (a role without dashboard-analytics access today) navigates to the dashboard? → Existing role-based access to the dashboard screen is unchanged by this feature; only the content within it is expanded for roles that already had access.
- What happens if the uploaded profile photo fails to save (e.g., storage error)? → The user sees an error and their previous photo is retained; no partial state.
- What happens when a user attempts to change only their photo, or only their password, in one submission? → Each is independently submittable; changing one does not require changing the other.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Products screen and the POS screen MUST replace their existing single-select artist and category filter controls with a searchable, checkbox-based dropdown supporting an explicit "All" option and multiple discrete selections.
- **FR-002**: The artist/category filter dropdowns MUST filter their visible options as the user types, case-insensitively and on partial matches.
- **FR-003**: Selecting "All" MUST clear any specific selections for that axis (and vice versa: selecting any specific option MUST deselect "All").
- **FR-004**: Product/POS listings MUST apply artist and category filter selections as a combined (AND-across-axis, OR-within-axis) filter, matching the existing filtering semantics already served by the product listing capability.
- **FR-005**: The sidebar MUST display the group currently labeled "Purchase" as "Pembelian".
- **FR-006**: The sidebar's visual styling for menu items with a submenu (default, hover, active, and expanded states) MUST match the styling used for menu items without a submenu.
- **FR-007**: The dashboard MUST present a set of shortcut actions to common tasks (at minimum: start a new sale, start a new pre-order, adjust stock, add a product), each navigating directly to the relevant screen.
- **FR-008**: Dashboard shortcuts MUST only be shown for actions the viewing user's role is already permitted to perform, per existing authorization rules.
- **FR-009**: The dashboard's daily-sales panel MUST offer a day/date-range filter that re-scopes the displayed sales figures.
- **FR-010**: The dashboard MUST present chart-based breakdowns of sales by category, by artist, and by event.
- **FR-011**: Dashboard analytics MUST be scoped to the currently active DEMO/LIVE data mode, consistent with how every other reporting screen in the app already scopes data.
- **FR-012**: Every dashboard section MUST offer a link to the fuller screen that provides more detail on that section's subject.
- **FR-013**: The dashboard MUST present additional summary statistics beyond sales-by-day/category/artist/event that give the viewer a fuller picture of store activity (at minimum: total sales for the active event, count of low-stock or out-of-stock products, count of pending pre-orders).
- **FR-014**: A logged-in user MUST be able to view their own profile details: name, username, role, and current photo.
- **FR-015**: A logged-in user MUST be able to change their own password by submitting their current password and a new password; the change is rejected if the current password does not match or the new password fails the existing password policy.
- **FR-016**: A logged-in user MUST be able to replace their own profile photo by uploading a new image; the upload is rejected with a clear error if the file is not a supported image type or exceeds the size limit, leaving the existing photo unchanged.
- **FR-017**: A changed profile photo MUST be reflected everywhere the user's avatar is currently shown in the app (e.g., app header/topbar).
- **FR-018**: Changing a user's own password MUST NOT terminate their current active session.

### Key Entities

- **Artist / Category filter selection**: A per-screen (Products, POS), per-session UI selection state consisting of zero or more selected artist IDs and zero or more selected category IDs, or an explicit "all" state per axis; not persisted beyond the current screen session.
- **Dashboard shortcut**: A named action (label, target destination, required permission) rendered conditionally based on the viewing user's role.
- **Sales statistic (dashboard)**: An aggregated, mode-scoped figure or chart series derived from existing order/sales data, grouped by day, category, artist, or event.
- **User profile**: The logged-in user's own account record, exposing name, username, role, photo, and providing password-change and photo-change actions scoped to self only (not another user's account).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can narrow the Products or POS list to a specific set of artists/categories using search-and-select in under 10 seconds, without needing to scroll an unfiltered list.
- **SC-002**: 100% of dashboard shortcut tiles shown to a given role navigate to a screen that role is authorized to use (zero permission-denied redirects from a shown shortcut).
- **SC-003**: An owner/admin can determine the store's top-performing category, artist, and event for the current period from the dashboard alone, without navigating to the Reports screen.
- **SC-004**: A user can change their own password or photo in a single profile-page visit, in under 1 minute, without contacting an admin.
- **SC-005**: The "Purchase" mislabel no longer appears anywhere in the sidebar for Indonesian-language users.
- **SC-006**: Visual review confirms zero color-treatment mismatches between parent-with-submenu and standalone sidebar menu items across default/hover/active states.

## Assumptions

- Dashboard shortcut and analytics visibility follows the app's existing role/menu-permission system (`canAccessMenu`, `isOwnerOrAdmin`, `canManageMasterData`) rather than introducing new permission concepts.
- "Select all, multiple or single" is delivered as one multi-select control with an "All" convenience option, not a separate mode toggle between single-select and multi-select — a single specific selection is just a multi-select with one item checked.
- Password change requires re-entry of the current password (standard self-service security practice) and reuses whatever password policy already governs account creation/reset.
- Profile photo upload reuses the existing file-upload size/type conventions already established elsewhere in the app (e.g., payment proof upload) unless a different convention is specified during planning.
- "Statistik lain yang diperlukan" (other needed statistics) is interpreted as: total sales for the active event, low/out-of-stock product count, and pending pre-order count — additional candidates may be added during planning without requiring a new spec.
- Dashboard, Products, and POS analytics/filters respect the existing DEMO/LIVE data-mode scoping already enforced elsewhere in the app; no new mode-scoping mechanism is introduced.
- This feature is frontend/UI-facing plus the minimum backend needed to support it (profile self-service endpoints, any new dashboard aggregate endpoints); it does not change existing authorization rules, licensing rules, or the master-data import/export pipeline.
