const async = require('async')
const MongoClient = require('mongodb').MongoClient
const FreePBX = new require('freepbx')()
const url = 'mongodb://localhost:27017/letschat'

FreePBX.connect()
  .then(freepbx => {
    MongoClient.connect(url, (err, db) => {
      if (err) {
        throw err
      }
      db
        .collection('users')
        .find()
        .toArray((err, result) => {
          if (err) {
            throw err
          }

          async.each(
            result,
            (user, next) => {
              freepbx.db.query(
                'SELECT * FROM xmpp_users WHERE username = ?',
                [user.username],
                (err, results, fields) => {
                  if (err) {
                    throw err
                  }
                  let querySearch = { username: user.username }
                  if (results[0]) {
                    // The user exists so proceed to update freepbxId
                    let newValue = { $set: { freepbxId: results[0].user } }
                    db
                      .collection('users')
                      .updateOne(querySearch, newValue, (err, res) => {
                        if (err) {
                          throw err
                        }
                        next()
                      })
                  } else {
                    // The user doesnt exist at xmpp_users table so proceed to delete in letschat
                    db
                      .collection('users')
                      .deleteOne(querySearch, (err, obj) => {
                        if (err) {
                          throw err
                        }
                        next()
                      })
                  }
                }
              )
            },
            err => {
              if (err) {
                throw err
              }
              db.close()
              process.exit()
            }
          )
        })
    })
  })
  .catch(err => {
    throw err
  })
