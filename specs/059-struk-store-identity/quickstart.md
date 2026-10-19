# Quickstart: verifying 059

1. Admin: set the store contact person, phone, and email; keep the receipt-logo setting on; upload a logo (Settings → Store identity).
2. Tablet: complete a sale and print the struk. Confirm the logo appears above the store name, readable at 58 mm, and the contact lines appear under the address (US1, US2).
3. Admin: turn the receipt-logo setting off, print another struk: no logo, text header and contact still present (FR-004).
4. Admin: clear the contact fields: no empty lines and no "null" (FR-002, SC-003).
5. Printer off: the struk waits in the queue and prints with its identity after reconnecting (SC-004).
6. Reprint an old order: the identity matches the original (FR-007).
7. Unit tests: `./gradlew --offline :app:testDebugUnitTest` in `android-pos/`.
