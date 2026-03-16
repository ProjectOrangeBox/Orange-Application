#!/bin/sh

mkdir var
mkdir var/logs
mkdir var/cache
mkdir var/uploads
mkdir var/downloads
mkdir var/temp
mkdir var/working

chmod 777 var/*

cp support/samples/sample.env .env

echo "Now run composer install\n";

