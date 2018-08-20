'use strict';

var async = require('async');
var MongoClient = require('mongodb').MongoClient;
var FreePBX = new require('freepbx')();
var url = 'mongodb://localhost:27017/letschat';

FreePBX.connect().then(function (freepbx) {
  MongoClient.connect(url, function (err, db) {
    if (err) {
      throw err;
    }
    db.collection('users').find().toArray(function (err, result) {
      if (err) {
        throw err;
      }

      async.each(result, function (user, next) {
        freepbx.db.query('SELECT * FROM xmpp_users WHERE username = ?', [user.username], function (err, results, fields) {
          if (err) {
            throw err;
          }
          var querySearch = { username: user.username };
          if (results[0]) {
            // The user exists so proceed to update freepbxId
            var newValue = { $set: { freepbxId: results[0].user } };
            db.collection('users').updateOne(querySearch, newValue, function (err, res) {
              if (err) {
                throw err;
              }
              next();
            });
          } else {
            // The user doesnt exist at xmpp_users table so proceed to delete in letschat
            db.collection('users').deleteOne(querySearch, function (err, obj) {
              if (err) {
                throw err;
              }
              next();
            });
          }
        });
      }, function (err) {
        if (err) {
          throw err;
        }
        db.close();
        process.exit();
      });
    });
  });
}).catch(function (err) {
  throw err;
});
