# Opportunities demo

Sample data for Test Company. Log in at [http://127.0.0.1:8080/app/login](http://127.0.0.1:8080/app/login) with `test@tester.com`, then **Send OTP**. Open **Search → Opportunities**.

The score is computed when the page loads. Weights are the constants in `app/Support/Prototype/RecoveryLikelihood.php`.

1. **Scan the queue.** Hot rows sit at the top. Search `ABC1234`. The reason should read like: 3 exact hits within 0.1 mi of Address 2 in the last 10 days, overnight cluster. Newest hit 6 hours ago. No check-in recorded. The score band is Hot.
2. **Sort and filter.** Sort by Client, then by Score again. Set Score band to Hot and turn on Unassigned only. `ABC1234` stays. `N4A101` (already on Jordan Hale) drops out. Clear filters when you are done looking.
3. **Open the order.** Click the `ABC1234` row. The breakdown lists each signal and its points. Address 2 ranks above Address 1. The timeline includes a historical hit scanned before the order, marked so it is not treated as fresh. The map draws 0.1-mile halos. Without a Google Maps key this is a coordinate plot, not tiles. Address 1 has a check-in. Address 2 says no check-in recorded.
4. **Dispatch.** In the drawer, choose Dispatch. Driver: Jordan Hale (North 4). Priority: High. Due by: Overnight. Note: `Check Oak Ave between 1 and 4am`. Submit. The toast says a stub notification was logged and nothing was sent. The row status becomes Dispatched.
5. **Reload.** Refresh the browser. Status, driver, note, and the active sort or filters are still there. Turn Unassigned only back on. `ABC1234` is gone from that view.
6. **Morning plan.** Open Morning plan. The company coverage outline is the dashed zone around Test Company HQ. Coral pins are Hot. Jordan's column lists his stops with straight-line miles. Drag a stop, use Up or Down, or move it to Priya Shah. Click **Suggest straight-line order** on Jordan. The new order remains after another reload.
7. **Close the loop.** Open `E2B202` (Priya, already Checked in) or set `ABC1234` to Checked in, then Recovered or Not found. Recovered and Not found leave the morning strip and stay on the queue with that status.

`OUT5501` is a hot pattern outside the 25-mile zone. `BAD1001` is an Invalid Scan penalty. `FUZ3390` is a probable plate and is not given hit points. `OLD4410` is a historical read from well before the order.
