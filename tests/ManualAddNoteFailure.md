# Manual test: recovery after `addNote` failure

This scenario helps to confirm that the improved transaction/retry logic in `bin/fetch_new.php`
rolls back changes and reprocesses an order when `AmoClient::addNote` fails on the first run.

1. Prepare a sandbox environment with a test order in Kaspi and ensure it appears in `orders_map`
   with `lead_id = 0`.
2. Temporarily modify `AmoClient::addNote()` to throw an exception (for example,
   `throw new RuntimeException('Simulated note failure');`).
3. Run `php bin/fetch_new.php`. The script should:
   * create the lead;
   * catch the simulated exception;
   * roll back the database transaction;
   * delete the newly created lead via `AmoClient::deleteLead()`;
   * reset the reservation flags in `orders_map` so the order stays pending.
4. Restore the original `addNote()` implementation (remove the simulated failure).
5. Run `php bin/fetch_new.php` again. The script should reprocess the same order,
   successfully add the note, commit the transaction, and persist the new `lead_id`.

Document the console output or logs from both runs to verify that the recovery works and that
no duplicate leads remain in amoCRM.
