# CHANGELOG DYAPICONNECT FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.1

- **E-invoicing lock**: once an invoice has been transmitted to the PDP (Factur-X electronic invoicing),
  it can no longer be deleted, un-validated or modified in Dolibarr — as required by law. Enforced by the
  module triggers (`BILL_DELETE` / `BILL_UNVALIDATE` / `BILL_MODIFY`) against a new read-only
  `dyapiconnect_transmitted` extrafield that DyaPi sets when the invoice reaches the certified platform.

## 1.0.0

First public release.
