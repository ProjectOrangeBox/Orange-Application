#!/bin/sh

cd ../

mkdir var
mkdir var/logs
mkdir var/cache
mkdir var/uploads
mkdir var/downloads
mkdir var/temp
mkdir var/working

chmod 777 var/*

mkdir packages

cd packages

git clone git@github.com:ProjectOrangeBox/OrangePackage.git Orange

git clone https://github.com/ProjectOrangeBox/Peels Peels

cd ..

cp support/samples/sample.env .env

echo "Now run composer install\n";

