<?php

// Synthetic, not production records. Fields mirror the supplied WHMCS schema.
return [
    'tblcurrencies' => [['id' => 1, 'code' => 'USD', 'rate' => '1.00000']],
    'tblclients' => [[
        'id' => 41, 'firstname' => 'Example', 'lastname' => 'Customer', 'email' => 'person@example.invalid',
        'currency' => 1, 'groupid' => 0, 'datecreated' => '2014-07-12', 'credit' => '20.00',
        'created_at' => '2014-07-12 18:22:00', 'status' => 'Active',
    ]],
    'tblcontacts' => [['id' => 51, 'userid' => 41, 'firstname' => 'Billing', 'email' => 'billing@example.invalid']],
    'tblusers' => [['id' => 61, 'email' => 'person@example.invalid', 'created_at' => '2014-07-12 18:22:00']],
    'tblusers_clients' => [['id' => 71, 'auth_user_id' => 61, 'client_id' => 41, 'owner' => 1, 'permissions' => '["invoices","tickets"]']],
    'tblinvoices' => [
        ['id' => 81, 'userid' => 41, 'invoicenum' => 'INV-2015/0001', 'date' => '2015-03-01', 'duedate' => '2015-03-10', 'datepaid' => '2015-03-06 23:14:59', 'subtotal' => '100.00', 'tax' => '0.00', 'tax2' => '0.00', 'total' => '100.00', 'credit' => '0.00', 'status' => 'Paid'],
        ['id' => 82, 'userid' => 41, 'invoicenum' => 'INV-2015/0002', 'date' => '2015-04-01', 'total' => '100.00', 'credit' => '20.00', 'status' => 'Unpaid', 'datepaid' => '0000-00-00 00:00:00'],
        ['id' => 83, 'userid' => 41, 'invoicenum' => 'INV-2015/0003', 'date' => '2015-05-01', 'total' => '100.00', 'credit' => '0.00', 'status' => 'Refunded'],
    ],
    'tblinvoiceitems' => [
        ['id' => 91, 'invoiceid' => 81, 'userid' => 41, 'type' => 'Hosting', 'relid' => 121, 'amount' => '100.00', 'description' => 'Synthetic historical service'],
        ['id' => 92, 'invoiceid' => 82, 'userid' => 41, 'type' => 'Hosting', 'relid' => 121, 'amount' => '100.00'],
        ['id' => 93, 'invoiceid' => 83, 'userid' => 41, 'type' => 'Item', 'relid' => 0, 'amount' => '100.00'],
    ],
    'tblaccounts' => [
        ['id' => 101, 'userid' => 41, 'currency' => 1, 'invoiceid' => 81, 'date' => '2015-03-05 13:22:01', 'amountin' => '40.00', 'amountout' => '0.00', 'fees' => '0.30', 'rate' => '1.00000', 'transid' => 'synthetic-payment-a', 'refundid' => 0],
        ['id' => 102, 'userid' => 41, 'currency' => 1, 'invoiceid' => 81, 'date' => '2015-03-06 23:14:59', 'amountin' => '60.00', 'amountout' => '0.00', 'fees' => '0.60', 'rate' => '1.00000', 'transid' => 'synthetic-payment-b', 'refundid' => 0],
        ['id' => 103, 'userid' => 41, 'currency' => 1, 'invoiceid' => 82, 'date' => '2015-04-05 09:01:02', 'amountin' => '30.00', 'amountout' => '0.00', 'fees' => '0.00', 'refundid' => 0],
        ['id' => 104, 'userid' => 41, 'currency' => 1, 'invoiceid' => 83, 'date' => '2015-05-05 09:01:02', 'amountin' => '100.00', 'amountout' => '0.00', 'fees' => '1.00', 'refundid' => 0],
        ['id' => 105, 'userid' => 41, 'currency' => 1, 'invoiceid' => 83, 'date' => '2015-05-06 09:01:02', 'amountin' => '0.00', 'amountout' => '100.00', 'fees' => '0.00', 'refundid' => 104],
    ],
    'tblcredit' => [['id' => 111, 'clientid' => 41, 'date' => '2015-04-05', 'amount' => '-20.00', 'relid' => 82]],
    'tblhosting' => [['id' => 121, 'userid' => 41, 'packageid' => 0, 'server' => 0, 'orderid' => 0, 'domain' => 'hosting.example.invalid', 'regdate' => '2014-07-12', 'nextduedate' => '2015-07-12', 'amount' => '100.00', 'domainstatus' => 'Cancelled']],
    'tblhostingaddons' => [['id' => 131, 'hostingid' => 121, 'userid' => 41, 'addonid' => 0, 'recurring' => '10.00', 'setupfee' => '5.00', 'status' => 'Terminated']],
    'tbldomains' => [['id' => 141, 'userid' => 41, 'orderid' => 0, 'domain' => 'domain.example.invalid', 'registrationdate' => '2014-07-12', 'expirydate' => '2015-07-12', 'status' => 'Expired', 'donotrenew' => 1]],
    'tblticketdepartments' => [['id' => 151, 'name' => 'Support']],
    'tbltickets' => [['id' => 161, 'tid' => 'ABC-123', 'userid' => 41, 'did' => 151, 'date' => '2015-01-01 13:00:00', 'lastreply' => '2015-01-03 14:00:00', 'message' => '<script>untrusted()</script>', 'attachment' => 'synthetic.pdf', 'status' => 'Closed', 'editor' => 'plain']],
    'tblticketreplies' => [
        ['id' => 171, 'tid' => 161, 'userid' => 41, 'date' => '2015-01-02 13:00:00', 'message' => 'Customer reply', 'admin' => ''],
        ['id' => 172, 'tid' => 161, 'userid' => 0, 'date' => '2015-01-03 14:00:00', 'message' => 'Staff reply', 'admin' => 'Historical Staff'],
    ],
    'tblticketnotes' => [['id' => 181, 'ticketid' => 161, 'date' => '2015-01-02 14:00:00', 'message' => 'Internal only', 'attachments' => '["private.txt"]', 'admin' => 'Historical Staff']],
    'tblemails' => [['id' => 191, 'userid' => 41, 'subject' => 'Original email', 'message' => 'Historical content', 'date' => '2015-03-06 23:15:00', 'attachments' => '["receipt.pdf"]', 'pending' => 1, 'message_data' => '{"secret":"DO-NOT-COPY"}']],
    'tblnotes' => [['id' => 201, 'userid' => 41, 'created' => '2014-07-12 18:30:00', 'modified' => '2014-07-12 18:40:00', 'note' => 'Original note', 'sticky' => 1]],
    'tblclientsfiles' => [['id' => 211, 'userid' => 41, 'filename' => 'private-contract.pdf', 'adminonly' => 1, 'dateadded' => '2014-07-12']],
];
