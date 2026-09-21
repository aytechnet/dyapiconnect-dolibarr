# CHANGELOG DYAPICONNECT FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.2

- **Supplier invoices notify DyaPi** (`BILL_SUPPLIER_VALIDATE` / `UNVALIDATE` / `DELETE` /
  `PAYED` / `UNPAYED` → `TYPE_SUPPLIER_INVOICE`): validating an imported draft in Dolibarr is
  the buyer's acceptance — DyaPi verifies the invoice's actual state and reports the "approved"
  lifecycle status to the e-invoicing platform automatically; deleting the draft makes the
  received invoice transferable again. One click less per received invoice.
- **Full Dolibarr coverage of the notification signal**: proposals, supplier proposals,
  contracts, interventions, tickets, shipments, receptions, supplier orders, users, members,
  agenda events, resources, warehouses and categories now emit their change signal too.
  Notifications carry only "object X of type Y changed" — DyaPi loads the object and verifies
  everything before any consequence, so unused signals are simply acknowledged and future DyaPi
  features will not require another module update.
- Deliberately silent: line-level events (the parent object suffices), payments (already
  signalled by `BILL_PAYED` / `BILL_SUPPLIER_PAYED`), stock movements and member subscriptions
  (their trigger object is not the interesting one), projects/tasks/groups/donations (outside
  the DyaPi object model).

## 1.0.1

- **E-invoicing lock**: once an invoice has been transmitted to the PDP (Factur-X electronic invoicing),
  it can no longer be deleted, un-validated or modified in Dolibarr — as required by law. Enforced by the
  module triggers (`BILL_DELETE` / `BILL_UNVALIDATE` / `BILL_MODIFY`) against a new read-only
  `dyapiconnect_transmitted` extrafield that DyaPi sets when the invoice reaches the certified platform.

## 1.0.0

First public release.
