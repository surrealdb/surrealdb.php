REMOVE NAMESPACE test;
REMOVE DATABASE test;

DEFINE TABLE person
    PERMISSIONS FULL;

DEFINE TABLE product
    PERMISSIONS FULL;

DEFINE TABLE likes
    PERMISSIONS FULL;

DEFINE TABLE test
    PERMISSIONS FULL;

DEFINE TABLE account
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
}