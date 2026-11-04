**Classification: INTERNAL**

# Feature Specification: Transaction Reference — Thumbnails in the Detail and Multiple Images

**Feature Branch**: `091-transaction-reference-images`

**Created**: 2026-11-04

**Status**: Draft

**Input**: User description: "in transaction details, show the reference image thumbnail and can upload multiple images"

**Builds on**: feature 054 (User Story 8, "Transaction reference (note or image)"), which allowed ONE image plus a text note per POS sale. Today the sale's detail only says that an image is attached; the image itself is visible only after opening the separate reference dialog. This feature (a) shows the reference images as thumbnails directly in the sale's detail and (b) lifts the one-image limit. It deliberately reverses 054's "one image per sale" assumption.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See reference images at a glance in the transaction detail (Priority: P1)

A shop owner opens a sale from the Sales page to check what the customer sent over WhatsApp before the sale was entered. Instead of a line of text saying "image attached" and a link to open another dialog, the detail shows a small thumbnail of every reference image, next to the reference note. Tapping a thumbnail opens that image full size; from there the user can move to the other images of the same sale.

**Why this priority**: It is the visible half of the request and works even with today's single image — a user immediately sees the evidence without an extra dialog. It is the smallest slice that delivers value.

**Independent Test**: Take a sale that already has one reference image (created under feature 054), open its detail, and confirm the image appears as a thumbnail without opening any other dialog; tap it and confirm the full-size image opens.

**Acceptance Scenarios**:

1. **Given** a sale with a reference that has one or more images, **When** a user allowed to view the reference opens the sale's detail, **Then** each image is shown as a thumbnail in the reference area, in the order the images were added, together with the note if there is one.
2. **Given** the thumbnails are shown, **When** the user taps one, **Then** that image opens full size without leaving the Sales page, and the user can step to the previous/next image of the same sale and close the view.
3. **Given** a sale whose reference has only a note and no image, **When** the detail opens, **Then** the note is shown and no empty thumbnail area or broken image appears.
4. **Given** a sale with no reference at all, **When** the detail opens, **Then** the reference area behaves as today (an "Add reference" action for users allowed to edit, nothing for others).
5. **Given** an image that fails to load (for example the file is missing), **When** the detail opens, **Then** that thumbnail shows a clear placeholder instead of a broken image, and the other thumbnails still appear.
6. **Given** the reference images are loading, **When** the detail is open, **Then** the rest of the sale detail (items, payments, totals) is usable immediately and is not blocked by the images.

---

### User Story 2 - Attach several images to one sale (Priority: P1)

A customer sends three screenshots for one order (the item list, the transfer proof, and the shipping address). The cashier who made the sale — or an owner/admin — opens the reference, selects several images at once (or adds them one after another), and saves. All of them are kept on the sale as its reference images.

**Why this priority**: It is the other explicit half of the request; one image per sale forces users to pick only one piece of evidence or to stitch screenshots together.

**Independent Test**: On a sale without a reference, choose three valid images in one selection, save, and confirm the detail now shows three thumbnails; add a fourth image later and confirm four thumbnails appear and the earlier three are unchanged.

**Acceptance Scenarios**:

1. **Given** an authorised user edits a sale's reference, **When** they select several valid images in one go and save, **Then** all selected images are attached to the sale and appear as thumbnails in the detail.
2. **Given** a sale already has reference images, **When** an authorised user adds more images and saves, **Then** the new images are added after the existing ones and the existing images are kept unchanged.
3. **Given** a selection contains a file that is not an accepted image type or is larger than the size limit, **When** the user tries to add it, **Then** that file is refused with a clear message naming the problem, and the valid files in the same selection can still be added.
4. **Given** a sale already holds the maximum number of reference images, **When** the user tries to add another, **Then** the addition is refused with a message stating the maximum, and nothing already attached is changed.
5. **Given** a selection would take the sale above the maximum, **When** the user saves, **Then** the save is refused as a whole with a message stating how many more images can be added; no image from that save is stored.
6. **Given** a save is in progress, **When** it fails (for example the connection drops), **Then** none of the images from that save are attached, the user is told it failed, and the images that were attached before remain intact.
7. **Given** a user who is not allowed to edit the reference (not owner/admin and not the sale's own cashier), **When** they view the sale, **Then** they can see the thumbnails but have no way to add images, and an attempt made outside the screen is refused.

---

### User Story 3 - Remove one image without losing the others (Priority: P2)

A cashier attached the wrong screenshot. They open the reference, remove just that one image, and save. The note and the other images stay.

**Why this priority**: With several images, "replace the image" (054's behaviour) no longer makes sense; users need to remove one specific image. It is P2 because adding and seeing images already deliver most of the value, and the whole reference can already be cleared.

**Independent Test**: On a sale with three reference images, remove the second one, save, and confirm two thumbnails remain (the first and third, in their original order) and the note is unchanged.

**Acceptance Scenarios**:

1. **Given** a sale with several reference images, **When** an authorised user removes one image and saves, **Then** only that image is removed; the note and the remaining images, in their order, are unchanged.
2. **Given** a reference with no note and a single image, **When** the user removes that image, **Then** the result is the same as removing the whole reference (a reference cannot be left completely empty).
3. **Given** an image has been removed, **When** anyone later tries to open it (including through an address saved earlier), **Then** it is no longer served.
4. **Given** the user marks images for removal but closes the dialog without saving, **When** they reopen the sale, **Then** nothing was removed.
5. **Given** any image is added or removed, **When** the change is saved, **Then** the activity log records who changed the reference of which sale, including how many images were added and removed.

---

### Edge Cases

- **Existing single-image references (from feature 054)**: they become the first (and only) image of that sale's reference; nothing is lost, and the user sees them as one thumbnail without any manual step.
- **Two users edit the same sale's reference at the same time**: each save applies only its own additions and removals; one user's save never silently drops images the other user added. The maximum is checked against what is actually stored at save time.
- **Removing an image that another user already removed**: the save still succeeds for the rest of the changes and the user is not shown an error for an image that is already gone.
- **Voided sale**: references stay viewable and editable, as in 054 (a reference is working evidence, not a financial record).
- **Very tall or very wide images**: thumbnails keep a uniform size and are cropped/fit for display only; the full-size view shows the whole image.
- **Many images on a small screen**: thumbnails wrap onto further rows inside the detail; the detail does not overflow the screen horizontally.
- **Slow connection**: thumbnails appear as they load; a slow image never blocks the other thumbnails or the rest of the detail.
- **A user who may open the Sales page but whose role was changed**: viewing still follows the existing rule; images are never reachable through a public address.
- **DEMO data cloned from LIVE**: every reference image is copied with its sale, as the single image is today, so deleting a DEMO image never removes a LIVE file.
- **Backup and restore**: reference images are kept on the same private storage as today's reference image and follow the same backup behaviour.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The sale's detail on the Sales page MUST show every reference image of that sale as a thumbnail in the reference area, in the order the images were added, without requiring the user to open a separate dialog.
- **FR-002**: Tapping a thumbnail MUST open that image at full size without leaving the Sales page, with the ability to step to the other images of the same sale and to close the view.
- **FR-003**: A thumbnail whose image cannot be loaded MUST show a clear placeholder, and MUST NOT prevent the other thumbnails or the rest of the detail from displaying.
- **FR-004**: An authorised user MUST be able to attach more than one image to a sale's reference, including selecting several images in a single action.
- **FR-005**: The system MUST limit a sale's reference to a maximum number of images (default 10) and refuse additions beyond it with a message that states the maximum and how many more can be added.
- **FR-006**: Each image MUST be checked individually against the accepted image types (based on the file's actual content, not its name) and the per-image size limit; refused files MUST be named with the reason.
- **FR-007**: A save that adds and/or removes images MUST be applied completely or not at all; a failed save MUST leave the previously stored images and note unchanged.
- **FR-008**: An authorised user MUST be able to remove one specific image while keeping the note and the other images, and the remaining images MUST keep their order.
- **FR-009**: A reference MUST NOT end up completely empty (no note and no image); removing the last remaining content is equivalent to removing the whole reference.
- **FR-010**: Removed images MUST no longer be served to anyone and MUST be permanently deleted (consistent with 054: a reference is working evidence, not a retained financial record); a save that fails MUST never leave an image that is still listed on the sale without its file.
- **FR-011**: Who may view and who may edit a reference MUST stay exactly as in feature 054 (view: anyone who can open the Sales page; add/remove: owner, admin, or the cashier who made that sale), enforced by the system and not only by hiding controls. Images MUST only be reachable through an authorising request, never a public address, and an image MUST only be served in the context of the sale it belongs to.
- **FR-012**: Every save that changes the reference MUST write one activity-log entry recording who changed which sale's reference and how many images were added and removed; it MUST NOT record image contents.
- **FR-013**: Adding or removing reference images MUST NOT change any amount, status, stock, payment or report figure.
- **FR-014**: Existing single-image references created under feature 054 MUST appear as a one-image reference after this change, with no data loss and no manual migration step by the user.
- **FR-015**: The Sales list's existing reference indicator MUST keep working: a sale with a note and/or at least one image shows it; a sale with neither shows none.
- **FR-016**: Concurrent edits to the same reference MUST NOT lose images added by another user; the maximum MUST be enforced against the images actually stored at the moment of saving.
- **FR-017**: Cloning LIVE data to DEMO MUST copy every reference image of every cloned sale as a separate file, as it does for the single image today.
- **FR-018**: The reference note behaviour (length limit, editing, clearing) MUST remain as in feature 054.

### Key Entities *(include if feature involves data)*

- **Transaction Reference (existing, extended)**: Evidence attached to one POS sale: an optional text note plus zero or more images, who last changed it and when. Carries no money. At most one reference per sale; it now owns an ordered list of images instead of a single image.
- **Reference Image (new)**: One image belonging to one sale's reference: its order position, its content type, when and by whom it was added, and its privately stored file. Removing it deletes the file; it is never shared between sales or between DEMO and LIVE data.
- **Sale / Transaction (existing)**: A POS order. Its list row keeps the existing "has reference" indicator, which is now true when the note or at least one image exists.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user opening a sale with reference images sees the thumbnails in the detail with zero extra taps (today: at least one extra tap to open the reference dialog).
- **SC-002**: A user can attach 3 images to a sale in a single selection and save in under 1 minute.
- **SC-003**: 100% of single-image references that existed before the change are still visible as one thumbnail afterwards.
- **SC-004**: On a typical booth connection, the sale detail's items, payments and totals are usable immediately; thumbnails for a sale with 10 images all appear within 3 seconds.
- **SC-005**: No reference image can be opened by a user outside the allowed roles, by a guessed address, or after it has been removed (verified by authorisation tests for every image path).
- **SC-006**: No save ever results in a partially applied set of additions/removals (verified by failure tests).

## Assumptions

- **Scope is POS sales only**, the same place feature 054's reference lives (Sales page detail, also reused by the customer history screen through the same detail dialog). Pre-orders, purchase orders and payment proofs are not changed; payment proofs remain a separate concept with their own rules.
- **Maximum 10 images per sale**, **5 MB per image**, accepted types JPEG, PNG and WebP (same types and per-image size as 054). The maximum can be tuned at planning time.
- **No captions, rotation, cropping or re-ordering** of images by the user; order is the order of adding. Reordering can be a later request.
- **The Sales list does not show thumbnails**; only the detail does, as requested. The list keeps its existing reference indicator.
- **Thumbnails are for display only**; the stored image is the uploaded file unchanged. Whether a smaller preview is generated is a planning decision, not a user-visible behaviour.
- **Removed or replaced images are deleted, not retained**, consistent with 054's decision that a reference is working evidence (the activity log is the trail).
- **Permissions are unchanged from 054** (see FR-011); this feature adds no new role or menu key.
- Downloaded receipts and exports do not include reference images (unchanged from 054).
