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

DEFINE TABLE user
    PERMISSIONS
        FOR select WHERE id = $auth.id;

DEFINE ACCESS account ON DATABASE TYPE RECORD
    SIGNUP ( CREATE user SET email = $email, pass = crypto::argon2::generate($pass) )
    SIGNIN ( SELECT * FROM user WHERE email = $email AND crypto::argon2::compare(pass, $pass))
    DURATION FOR TOKEN 15m, FOR SESSION 12h
;

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