CREATE TABLE products (
    sku text PRIMARY KEY CHECK (length(sku) BETWEEN 1 AND 128),
    name text NOT NULL,
    type text NOT NULL CHECK (type IN ('key','topup','subscription','giftcard')),
    price_minor bigint NOT NULL CHECK (price_minor > 0),
    currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    available_stock integer NOT NULL DEFAULT 0 CHECK (available_stock >= 0),
    active boolean NOT NULL DEFAULT true
);
CREATE INDEX catalog_type_price ON products (type,price_minor,sku)
    INCLUDE (name,currency,available_stock) WHERE active AND available_stock > 0;
CREATE INDEX catalog_price ON products (price_minor,sku)
    INCLUDE (name,type,currency,available_stock) WHERE active AND available_stock > 0;

CREATE TABLE orders (
    id text PRIMARY KEY CHECK (length(id) BETWEEN 1 AND 128),
    sku text NOT NULL REFERENCES products(sku),
    amount_minor bigint NOT NULL CHECK (amount_minor > 0),
    currency char(3) NOT NULL,
    status text NOT NULL DEFAULT 'created' CHECK (status IN ('created','paid','delivering','delivered','payment_failed','out_of_stock','delivery_failed')),
    paid_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((status IN ('created','payment_failed') AND paid_at IS NULL)
        OR (status IN ('paid','delivering','delivered','out_of_stock','delivery_failed') AND paid_at IS NOT NULL))
);
CREATE INDEX orders_recoverable ON orders(updated_at,id) WHERE paid_at IS NOT NULL AND status <> 'delivered';

-- No FK on order_id: a gateway notification may legitimately precede order creation.
CREATE TABLE payment_events (
    event_id text PRIMARY KEY,
    order_id text NOT NULL,
    status text NOT NULL CHECK (status IN ('paid','failed')),
    amount_minor bigint NOT NULL CHECK (amount_minor > 0),
    currency char(3) NOT NULL,
    occurred_at timestamptz NOT NULL,
    payload_hash text NOT NULL,
    state text NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','applied','ignored','rejected')),
    reason text,
    created_at timestamptz NOT NULL DEFAULT now(),
    processed_at timestamptz
);
CREATE INDEX payment_events_pending ON payment_events(order_id,occurred_at,event_id) WHERE state = 'pending';
CREATE INDEX payment_events_order ON payment_events(order_id);

CREATE TABLE delivery_jobs (
    order_id text PRIMARY KEY REFERENCES orders(id),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    available_at timestamptz NOT NULL DEFAULT now(),
    last_error text,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX delivery_jobs_due ON delivery_jobs(available_at,created_at,order_id);
CREATE TABLE delivery_attempts (
    id bigserial PRIMARY KEY,
    order_id text NOT NULL REFERENCES orders(id),
    provider text NOT NULL CHECK (provider IN ('A','B')),
    request_id text NOT NULL UNIQUE,
    state text NOT NULL CHECK (state IN ('pending','in_flight','ambiguous','succeeded','rejected')),
    reason text,
    calls integer NOT NULL DEFAULT 0 CHECK (calls >= 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (request_id,order_id,provider)
);
CREATE UNIQUE INDEX delivery_one_active_attempt ON delivery_attempts(order_id) WHERE state IN ('pending','in_flight','ambiguous');
CREATE INDEX delivery_attempts_order ON delivery_attempts(order_id,id);
CREATE TABLE deliveries (
    order_id text PRIMARY KEY REFERENCES orders(id),
    provider text NOT NULL CHECK (provider IN ('A','B')),
    request_id text NOT NULL UNIQUE,
    code text NOT NULL UNIQUE,
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (request_id,order_id,provider) REFERENCES delivery_attempts(request_id,order_id,provider)
);

CREATE TABLE ledger_transactions (
    id bigserial PRIMARY KEY,
    order_id text NOT NULL REFERENCES orders(id),
    kind text NOT NULL CHECK (kind IN ('payment','delivery')),
    currency char(3) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (order_id,kind)
);
CREATE TABLE ledger_entries (
    id bigserial PRIMARY KEY,
    transaction_id bigint NOT NULL REFERENCES ledger_transactions(id),
    account text NOT NULL CHECK (account IN ('cash','customer_liability','revenue')),
    amount_minor bigint NOT NULL CHECK (amount_minor <> 0),
    UNIQUE (transaction_id,account)
);

-- Guard both the total AND the business meaning at COMMIT, not after each leg.
CREATE FUNCTION check_ledger_transaction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE txid bigint; tx ledger_transactions; ord orders; n bigint; balance numeric; expected_positive text; expected_negative text;
BEGIN
    -- Separate statements avoid resolving fields absent from the other trigger's NEW record.
    IF TG_TABLE_NAME = 'ledger_transactions' THEN
        txid := NEW.id;
    ELSE
        txid := NEW.transaction_id;
    END IF;
    SELECT * INTO STRICT tx FROM ledger_transactions WHERE id = txid;
    SELECT * INTO STRICT ord FROM orders WHERE id = tx.order_id;
    SELECT count(*), coalesce(sum(amount_minor),0) INTO n,balance FROM ledger_entries WHERE transaction_id=txid;
    expected_positive := CASE WHEN tx.kind='payment' THEN 'cash' ELSE 'customer_liability' END;
    expected_negative := CASE WHEN tx.kind='payment' THEN 'customer_liability' ELSE 'revenue' END;
    IF n <> 2 OR balance <> 0 OR tx.currency <> ord.currency OR ord.paid_at IS NULL
       OR NOT EXISTS (SELECT 1 FROM ledger_entries WHERE transaction_id=txid AND account=expected_positive AND amount_minor=ord.amount_minor)
       OR NOT EXISTS (SELECT 1 FROM ledger_entries WHERE transaction_id=txid AND account=expected_negative AND amount_minor=-ord.amount_minor)
       OR (tx.kind='delivery' AND NOT EXISTS (SELECT 1 FROM deliveries WHERE order_id=ord.id)) THEN
        RAISE EXCEPTION 'Invalid or unbalanced ledger transaction %', txid USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER ledger_transaction_balanced AFTER INSERT ON ledger_transactions
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_ledger_transaction();
CREATE CONSTRAINT TRIGGER ledger_entry_balanced AFTER INSERT ON ledger_entries
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_ledger_transaction();
CREATE FUNCTION reject_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Rows in % are immutable', TG_TABLE_NAME USING ERRCODE='23514'; END;
$$;
CREATE TRIGGER ledger_transactions_immutable BEFORE UPDATE OR DELETE ON ledger_transactions FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TRIGGER ledger_entries_immutable BEFORE UPDATE OR DELETE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TRIGGER deliveries_immutable BEFORE UPDATE OR DELETE ON deliveries FOR EACH ROW EXECUTE FUNCTION reject_mutation();

CREATE SCHEMA supplier;
CREATE TABLE supplier.settings (
    provider text PRIMARY KEY CHECK (provider IN ('A','B')),
    mode text NOT NULL DEFAULT 'normal' CHECK (mode IN ('normal','unavailable','out_of_stock','timeout_after_issue','timeout_before_issue','random')),
    error_rate double precision NOT NULL DEFAULT 0 CHECK (error_rate BETWEEN 0 AND 1),
    timeout_rate double precision NOT NULL DEFAULT 0 CHECK (timeout_rate BETWEEN 0 AND 1),
    delay_ms integer NOT NULL DEFAULT 1200 CHECK (delay_ms BETWEEN 0 AND 60000),
    CHECK (error_rate + timeout_rate <= 1)
);
CREATE TABLE supplier.stock (
    id bigserial PRIMARY KEY,
    provider text NOT NULL CHECK (provider IN ('A','B')),
    sku text NOT NULL,
    code text NOT NULL UNIQUE,
    request_id text UNIQUE
);
CREATE INDEX supplier_free_stock ON supplier.stock(provider,sku,id) INCLUDE(code) WHERE request_id IS NULL;
CREATE TABLE supplier.requests (
    provider text NOT NULL CHECK (provider IN ('A','B')),
    request_id text NOT NULL,
    order_id text NOT NULL,
    sku text NOT NULL,
    state text NOT NULL CHECK (state IN ('issued','rejected')),
    code text,
    reason text,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(provider,request_id),
    CHECK ((state='issued' AND code IS NOT NULL AND reason IS NULL) OR (state='rejected' AND code IS NULL AND reason IS NOT NULL))
);
CREATE UNIQUE INDEX supplier_order_issued_once ON supplier.requests(provider,order_id) WHERE state='issued';
CREATE TRIGGER supplier_requests_immutable BEFORE UPDATE OR DELETE ON supplier.requests FOR EACH ROW EXECUTE FUNCTION reject_mutation();
CREATE TABLE supplier.calls (
    id bigserial PRIMARY KEY,
    provider text NOT NULL CHECK (provider IN ('A','B')),
    request_id text NOT NULL,
    order_id text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX supplier_calls_order ON supplier.calls(order_id,provider,request_id);
