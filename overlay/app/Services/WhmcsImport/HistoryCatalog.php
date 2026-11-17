<?php

namespace App\Services\WhmcsImport;

/**
 * Explicit safe columns from the supplied WHMCS 8.8 schema.
 * Opaque payloads and executable/secrets configuration are excluded.
 * References describe expected links for validation, not proven foreign keys.
 */
final class HistoryCatalog
{
    public static function definitions(): array
    {
        return [
            'currencies' => self::entry('currencies', 'id code prefix suffix format rate default'),
            'groups' => self::entry('clientgroups', 'id groupname groupcolour discountpercent susptermexempt separateinvoices'),
            'clients' => self::entry('clients', 'id uuid firstname lastname companyname email address1 address2 city state postcode country phonenumber tax_id currency defaultgateway credit taxexempt latefeeoveride overideduenotices separateinvoices disableautocc datecreated notes billingcid groupid lastlogin ip status language emailoptout marketing_emails_opt_in overrideautoclose email_verified created_at updated_at', ['currency' => 'currencies', 'groupid' => 'clientgroups']),
            'contacts' => self::entry('contacts', 'id userid firstname lastname companyname email address1 address2 city state postcode country phonenumber tax_id subaccount permissions domainemails generalemails invoiceemails productemails supportemails affiliateemails created_at updated_at', ['userid' => 'clients']),
            'users' => self::entry('users', 'id first_name last_name email language last_ip last_login email_verified_at created_at updated_at'),
            'user_links' => self::entry('users_clients', 'id auth_user_id client_id owner permissions created_at updated_at', ['auth_user_id' => 'users', 'client_id' => 'clients']),
            'orders' => self::entry('orders', 'id ordernum userid contactid requestor_id admin_requestor_id date promocode promotype promovalue amount paymentmethod invoiceid status ipaddress notes', ['userid' => 'clients', 'contactid' => 'contacts', 'invoiceid' => 'invoices']),
            'invoices' => self::entry('invoices', 'id userid invoicenum date duedate datepaid date_refunded date_cancelled subtotal credit tax tax2 total taxrate taxrate2 status paymentmethod notes created_at updated_at', ['userid' => 'clients']),
            'invoice_items' => self::entry('invoiceitems', 'id invoiceid userid type relid description amount taxed duedate paymentmethod notes', ['invoiceid' => 'invoices', 'userid' => 'clients']),
            'transactions' => self::entry('accounts', 'id userid currency gateway date description amountin fees amountout rate transid invoiceid refundid', ['userid' => 'clients', 'currency' => 'currencies', 'invoiceid' => 'invoices']),
            'credits' => self::entry('credit', 'id clientid admin_id date description amount relid', ['clientid' => 'clients']),
            'product_groups' => self::entry('productgroups', 'id name slug headline tagline hidden order created_at updated_at'),
            'products' => self::entry('products', 'id type gid name slug description hidden showdomainoptions paytype allowqty autosetup servertype servergroup tax retired is_featured created_at updated_at', ['gid' => 'productgroups']),
            'pricing' => self::entry('pricing', 'id type currency relid msetupfee qsetupfee ssetupfee asetupfee bsetupfee tsetupfee monthly quarterly semiannually annually biennially triennially', ['currency' => 'currencies']),
            'servers' => self::entry('servers', 'id name ipaddress hostname type active disabled'),
            'services' => self::entry('hosting', 'id userid orderid packageid server regdate domain paymentmethod qty firstpaymentamount amount billingcycle nextduedate nextinvoicedate termination_date completed_date domainstatus username notes promoid promocount suspendreason overideautosuspend overidesuspenduntil dedicatedip assignedips ns1 ns2 diskusage disklimit bwusage bwlimit lastupdate created_at updated_at', ['userid' => 'clients', 'orderid' => 'orders', 'packageid' => 'products', 'server' => 'servers']),
            'addon_definitions' => self::entry('addons', 'id packages name description billingcycle allowqty tax hidden retired type module created_at updated_at'),
            'addons' => self::entry('hostingaddons', 'id orderid hostingid addonid userid server name qty firstpaymentamount setupfee recurring billingcycle tax status regdate nextduedate nextinvoicedate termination_date paymentmethod notes created_at updated_at', ['hostingid' => 'hosting', 'userid' => 'clients', 'addonid' => 'addons']),
            'domains' => self::entry('domains', 'id userid orderid type registrationdate domain firstpaymentamount recurringamount registrar registrationperiod expirydate promoid status nextduedate nextinvoicedate additionalnotes paymentmethod dnsmanagement emailforwarding idprotection is_premium donotrenew created_at updated_at', ['userid' => 'clients', 'orderid' => 'orders']),
            // Arbitrary custom values can contain secrets: definitions only until reviewed.
            'custom_fields' => self::entry('customfields', 'id type relid fieldname fieldtype description fieldoptions adminonly required showorder showinvoice sortorder created_at updated_at'),
            'departments' => self::entry('ticketdepartments', 'id name description email clientsonly hidden order feedback_request'),
            'ticket_statuses' => self::entry('ticketstatuses', 'id title color sortorder showactive showawaiting autoclose'),
            'tickets' => self::entry('tickets', 'id tid did userid contactid requestor_id name email cc date title message status urgency admin attachment attachments_removed lastreply flag service merged_ticket_id editor updated_at', ['did' => 'ticketdepartments', 'userid' => 'clients', 'contactid' => 'contacts', 'merged_ticket_id' => 'tickets']),
            'replies' => self::entry('ticketreplies', 'id tid userid contactid requestor_id name email date message admin attachment attachments_removed rating editor', ['tid' => 'tickets', 'userid' => 'clients', 'contactid' => 'contacts']),
            'ticket_notes' => self::entry('ticketnotes', 'id ticketid admin date message attachments attachments_removed editor', ['ticketid' => 'tickets']),
            'emails' => self::entry('emails', 'id userid subject message date to cc bcc attachments pending failed failure_reason retry_count created_at updated_at campaign_id', ['userid' => 'clients']),
            'notes' => self::entry('notes', 'id userid adminid created modified note sticky', ['userid' => 'clients']),
            'client_files' => self::entry('clientsfiles', 'id userid title filename adminonly dateadded', ['userid' => 'clients']),
            'cancellations' => self::entry('cancelrequests', 'id date relid reason type created_at updated_at', ['relid' => 'hosting']),
            'config_groups' => self::entry('productconfiggroups', 'id name description'),
            'config_options' => self::entry('productconfigoptions', 'id gid optionname optiontype qtyminimum qtymaximum order hidden', ['gid' => 'productconfiggroups']),
            'config_choices' => self::entry('productconfigoptionssub', 'id configid optionname sortorder hidden', ['configid' => 'productconfigoptions']),
            'service_options' => self::entry('hostingconfigoptions', 'id relid configid optionid qty', ['relid' => 'hosting', 'configid' => 'productconfigoptions', 'optionid' => 'productconfigoptionssub']),
            'consents' => self::entry('marketing_consent', 'id userid opt_in admin ip_address created_at updated_at', ['userid' => 'clients']),
            'quotes' => self::entry('quotes', 'id subject stage validuntil userid firstname lastname companyname email currency subtotal tax1 tax2 total proposal customernotes adminnotes datecreated lastmodified datesent dateaccepted', ['userid' => 'clients']),
            'quote_items' => self::entry('quoteitems', 'id quoteid description quantity unitprice discount taxable created_at updated_at', ['quoteid' => 'quotes']),
            'billable_items' => self::entry('billableitems', 'id userid description hours amount recur recurcycle recurfor invoiceaction unit duedate invoicecount', ['userid' => 'clients']),
            'ticket_logs' => self::entry('ticketlog', 'id date tid action', ['tid' => 'tickets']),
            'taxes' => self::entry('tax', 'id level name state country taxrate'),
        ];
    }

    private static function entry(string $table, string $fields, array $references = []): array
    {
        return ['table' => 'tbl'.$table, 'fields' => explode(' ', $fields), 'references' => array_combine(
            array_keys($references), array_map(fn ($name) => 'tbl'.$name, array_values($references)),
        ) ?: []];
    }

    public static function blockers(): array
    {
        return [
            'mod_invoicedata' => 'Buyer snapshot encoding not verified; no opaque payload copying.',
            'tblemails.message_data' => 'May contain credentials; decoder disabled pending format review.',
            'tblaccounts.refundid' => 'Direction/meaning unverified; evidence only.',
            'tblcredit.relid' => 'Meaning unverified; evidence only.',
            'attachments' => 'References only; filename conventions/bytes not verified.',
            'datetime' => 'Raw values retained; timezone conversions disabled.',
            'finance' => 'Historical archive only; native financial writes disabled.',
            'custom_values' => 'Potential secrets require field-by-field review.',
        ];
    }
}
