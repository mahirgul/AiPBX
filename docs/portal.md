# Portal basics

## Saving and applying changes

Telephony pages (extensions, trunks, routes, queues, IVRs, …) save to the database first. The change
is listed on **Pending Changes** and reaches Asterisk only when you press **Apply**:

1. the portal regenerates the affected files under `/etc/asterisk/pbx/`;
2. Asterisk reloads them;
3. if the reload fails, the previous working file is restored and the error is shown.

A change stays in the pending list until it has really been written and loaded, so a failed Apply can
simply be retried. Reloads do not drop calls.

## Edit dialogs

- Edit dialogs close only with their **✕** or **Cancel** button. Clicking outside the dialog or
  pressing <kbd>Esc</kbd> does not close them, so half-finished input is not lost. Viewers (PDF,
  recordings) still close on an outside click.
- Dialogs open at a fixed distance from the top of the screen; switching tabs only moves their bottom
  edge.
- Dialogs with tabs (trunks, queues) grow with the screen so all tabs fit; on narrow screens the tab row
  scrolls.

## Ordering lists

Trunks and outbound routes have a drag handle (⋮⋮) at the start of each row. Drag a row to its new
place; the order is saved immediately. New records are added at the end.

- **Trunks:** the first active trunk is the *primary trunk*, used when an outbound route has no trunk
  and for sending faxes.
- **Outbound routes:** the order is used for the list and the generated dialplan. Which route handles
  a number is decided by pattern specificity, not by order (see [Outbound routes](outbound-routes.md)).

## Copying records

Trunks and outbound routes have a **Copy** button next to *Edit*. It opens a new-record dialog filled
with all settings of the original:

- a copied trunk gets the system name `<name>_copy` — change it and, usually, the IP address;
- a copied route gets the name `<name> (kopya)` with the cursor in the pattern field — two routes in the
  same group cannot have the same pattern.
