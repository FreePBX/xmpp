'use strict';

const MongoClient = require('mongodb').MongoClient;
const url = 'mongodb://localhost:27017/letschat';

let args = process.argv.slice(2);
let buffer = new Buffer(args[0], 'base64');
const data = JSON.parse(buffer.toString());

MongoClient.connect(url, (err, db) => {
  if (err) {
    throw err;
  }
  let newUser = {
    uuid: data.uuid,
    username: data.username,
    displayName: data.extraData.displayname,
    firstName: data.extraData.fname,
    lastName: data.extraData.lname,
    freepbxId: data.freepbxId,
    password: '',
    email: data.extraData.email,
    rooms: [],
    openRooms: [],
    openPrivateMessages: [],
    provider: 'local',
    __v : 2,
    messages: [],
    joined: Date.now()
  }
  newUser.token = '';
  db.collection('users').insertOne(newUser, (err, result) => {
    if (err) {
      throw err;
    }
  });
  db.close();
});
