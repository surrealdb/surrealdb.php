REMOVE NAMESPACE test;
REMOVE DATABASE test;

DEFINE NAMESPACE test;
DEFINE DATABASE test;

DEFINE TABLE order
    PERMISSIONS FULL;

DEFINE TABLE table
    PERMISSIONS FULL;

DEFINE TABLE person
    PERMISSIONS FULL;

DEFINE TABLE product
    PERMISSIONS FULL;

DEFINE TABLE likes
    PERMISSIONS FULL;

DEFINE TABLE test
    PERMISSIONS FULL;

DEFINE TABLE future_test
	PERMISSIONS FULL;

DEFINE TABLE dates
    PERMISSIONS FULL;

DEFINE TABLE user
    PERMISSIONS
        FOR select WHERE id = $auth.id;

DEFINE SCOPE account SESSION 24h
    SIGNUP ( CREATE user SET email = $email, pass = crypto::argon2::generate($pass) )
    SIGNIN ( SELECT * FROM user WHERE email = $email AND crypto::argon2::compare(pass, $pass)
);

DEFINE USER admin ON ROOT PASSWORD "123!" ROLES OWNER;
DEFINE USER julian ON NAMESPACE PASSWORD "123!" ROLES EDITOR;
DEFINE USER beau ON DATABASE PASSWORD "123!" ROLES EDITOR;

CREATE user CONTENT {
    "email": "beau@user.nl",
    "pass": crypto::argon2::generate("123!")
};

DEFINE FUNCTION fn::greet($name: string) {
	RETURN "Hello, " + $name + "!";
};

DELETE dates:1;
CREATE dates:1 CONTENT {
    timestamp: d'2025-03-31T08:10:38.821Z'
};