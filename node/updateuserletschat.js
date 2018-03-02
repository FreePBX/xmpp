'use strict'
var MongoClient = require('mongodb').MongoClient;
var url = 'mongodb://localhost:27017/letschat';

var args = process.argv.slice(2);
var buffer = new Buffer(args[0], 'base64');
var data = JSON.parse(buffer.toString());

var querySearch = {
  freepbxId: data.id.toString()
};

MongoClient.connect(url, (err, db) => {
  if (err) {
    throw err;
  }
  db.collection('users').findOneAndUpdate(querySearch, { $set: { username: data.username } });
  db.close();
})
