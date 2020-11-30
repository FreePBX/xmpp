'use strict';

var MongoClient = require('mongodb').MongoClient;
var url = 'mongodb://localhost:27017/letschat';

let args = process.argv.slice(2);
let buffer = new Buffer(args[0], 'base64');
const data = JSON.parse(buffer.toString());

const querySearch = {
  freepbxId: data['id']
}

MongoClient.connect(url, (err, db) => {
  if (err) {
    throw err;
  }

  db.collection('users').deleteOne(querySearch, function (err, obj) {
     if (err) {
       throw err;
     }
  });
  db.close();
});
