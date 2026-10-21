<?php
declare(strict_types=1);
/** Synthetic signing key used only by offline tests. Never a trusted production certificate. */
function feedback_fixture_signer(): array {
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    if(!$key)throw new RuntimeException('Cannot create the synthetic signing key.');
    $request=openssl_csr_new(['commonName'=>'local-sns-fixture.invalid'],$key,['digest_alg'=>'sha256']);
    $certificate=openssl_csr_sign($request,null,$key,2,['digest_alg'=>'sha256']);
    if(!$certificate||!openssl_x509_export($certificate,$pem))throw new RuntimeException('Cannot create a synthetic certificate.');
    return [$key,$pem];
}
function feedback_fixture_message(array $event,array $overrides=[]): array {
    return array_replace([
        'Type'=>'Notification','MessageId'=>vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4)),
        'TopicArn'=>mail_feedback_topic(),'Message'=>json_encode($event,JSON_THROW_ON_ERROR),
        'Timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'SignatureVersion'=>'2','Signature'=>'placeholder',
        'SigningCertURL'=>'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-syntheticfixture.pem',
    ],$overrides);
}
function feedback_fixture_signed(array $message,OpenSSLAsymmetricKey $key): string {
    $validator=new \Aws\Sns\MessageValidator();
    $content=$validator->getStringToSign(new \Aws\Sns\Message($message));
    if(!openssl_sign($content,$signature,$key,$message['SignatureVersion']==='1'?OPENSSL_ALGO_SHA1:OPENSSL_ALGO_SHA256))throw new RuntimeException('Cannot sign a synthetic SNS envelope.');
    $message['Signature']=base64_encode($signature);
    return json_encode($message,JSON_THROW_ON_ERROR);
}
