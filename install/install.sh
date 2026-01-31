#!/bin/sh

cd ../

mkdir var/logs
mkdir var/cache
mkdir var/uploads
mkdir var/downloads

chmod 777 var/*

mkdir packages

git clone git@github.com:ProjectOrangeBox/OrangePackage.git Orange

git clone https://github.com/ProjectOrangeBox/Peels Peels

cp support/samples/sample.env .env

echo "Now run composer install\n";

